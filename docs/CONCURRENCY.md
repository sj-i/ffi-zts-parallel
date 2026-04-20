# ffi-zts-parallel concurrency design

Status: **Draft** -- satellite-specific design for the
cross-thread concurrency primitives (channels, pool, structured
combinators, atomics). The overarching design, zero-copy payload
mechanism, safety model, and naming discipline live in the core
package:

- [`ffi-zts` docs/CONCURRENCY.md](https://github.com/sj-i/ffi-zts/blob/main/docs/CONCURRENCY.md)
- [`ffi-zts` docs/concurrency/payload.md](https://github.com/sj-i/ffi-zts/blob/main/docs/concurrency/payload.md)
- [`ffi-zts` docs/concurrency/safety.md](https://github.com/sj-i/ffi-zts/blob/main/docs/concurrency/safety.md)
- [`ffi-zts` docs/concurrency/api.md](https://github.com/sj-i/ffi-zts/blob/main/docs/concurrency/api.md)
- [`ffi-zts` docs/concurrency/ecosystem.md](https://github.com/sj-i/ffi-zts/blob/main/docs/concurrency/ecosystem.md)
- [`ffi-zts` docs/concurrency/limits.md](https://github.com/sj-i/ffi-zts/blob/main/docs/concurrency/limits.md)

This document covers only the parts that are specific to moving
payloads between OS threads via `parallel\Runtime`.

## 1. What this package adds on top of `parallel`

Raw `parallel\Channel::send()` serialises its argument with
`igbinary_serialize` (or native serialisation if igbinary is
unavailable). For large payloads this dominates. The satellite
wraps `parallel\Channel` so that sending a `Payload` transmits
only a small header (pointer + length + arena id), and receiving
materialises the payload by CV injection on the worker side.

```
+-----------+              parallel\Channel               +-----------+
|  main     |  -------- small header only -------->      |  worker    |
|  thread   |                                             |  thread    |
|           |                                             |            |
|  Arena    |  persistent zend_string (shared malloc)     |  CV        |
|  holds    |  <-------------------------------------->   |  injection |
|  pointer  |         (by pointer, not copied)            |  reads     |
+-----------+                                             +-----------+
```

The wire format across the underlying channel is a tiny fixed
struct:

```
struct handoff_msg {
    uint64_t  zstr_ptr;     // cast to zend_string* on receive
    uint64_t  len;
    uint64_t  arena_id;     // for accounting / tracing
    uint32_t  kind;         // string | frozen_array | frozen_object_graph
    uint32_t  flags;
};
```

`parallel\Channel` carries this by value; it is a handful of
bytes and the cost of its serialisation is negligible.

## 2. Synchronous `Channel`

Phase 2 entry-level API. Wraps a `parallel\Channel` with
payload-aware send and receive:

```php
use SjI\FfiZts\Parallel\Concurrent\Channel;

$ch = Channel::open('jobs');

// producer
/**
 * @param-out Payload<Drained> $p
 */
$ch->send(Payload $p): void;

// consumer (inside a parallel\Runtime worker)
$ch->recvInto(string $cvName): void;
```

Under the hood:

1. `send()` calls `$payload->take()` which drains the handle,
   returning a raw pointer. Aliasing check runs here.
2. The pointer is packed into the `handoff_msg` struct and sent
   through the underlying `parallel\Channel`.
3. On the receiving side, the struct arrives, and the
   `CvInjector` writes a `zend_string*` zval into the CV named
   `$cvName` in the caller's frame.
4. Receiver's CV dtor fires on scope exit, `pefree` releases
   the persistent zstr.

`Channel` is the default for the common case where the consumer
blocks on receipt until something arrives. It integrates cleanly
with existing `parallel` code that expects blocking channels.

## 3. `AsyncChannel`

Phase 2 second half. Same send / receive semantics, but exposes
an `fd()` so receivers running inside an event loop can wait on
it alongside other I/O:

```php
use SjI\FfiZts\Parallel\Concurrent\AsyncChannel;
use Revolt\EventLoop;

$ch = AsyncChannel::open('jobs');

EventLoop::onReadable($ch->fd(), function () use ($ch) {
    while ($ch->tryRecvInto('buf')) {
        /** @var string $buf */
        EventLoop::queue(fn () => process($buf));
    }
});
```

Implementation:

- **Linux:** `eventfd(0, EFD_CLOEXEC | EFD_NONBLOCK)` created per
  channel, shared across threads. `send()` increments the
  counter; `tryRecvInto()` drains the queue until `read()` on
  the eventfd returns `EAGAIN`.
- **macOS / BSD:** `pipe2()` fallback. Write a single byte on
  send, drain on receive. Slightly higher per-message cost but
  functionally equivalent.
- **Windows:** out of scope for v1. A later adapter using
  `CreateEvent` + `WaitForMultipleObjects` is feasible when
  demand appears.

The message queue backing the channel is a persistent ring
buffer (lock-based in v1; lock-free considered for a later
phase if profiling motivates it).

### 3.1 Integration with an external reactor

If the PHP ecosystem standardises a reactor C API (for instance
via an adjacent RFC; we track these but do not depend on them),
a `LibUvAsyncChannel` or similar adapter can be added that
registers the channel's `uv_async_t` with the shared reactor
directly. The user-visible `AsyncChannel` interface stays
stable; only the implementation switches. See the core package's
`docs/concurrency/limits.md` §3.2 for context.

## 4. `Pool`

A long-lived set of `parallel\Runtime` workers reused across
tasks:

```php
use SjI\FfiZts\Parallel\Concurrent\Pool;

$pool = Pool::withWorkers(8);

$future = $pool->submit(
    fn () => analyse(),
    bindings: ['row' => $payload],
);

$result = $future->get();

$pool->shutdown();                  // drains, then joins
```

Design points:

- Runtimes are created on first `submit()` up to the pool size.
- Each worker runs an internal dispatch loop that receives
  tasks via the pool's private `Channel`.
- `bindings` are applied to the worker's task closure via
  CV injection immediately before the closure runs.
- `submit()` returns a `Future`; `get()` blocks, `tryGet()` is
  non-blocking, `cancel()` sets a cooperative cancellation
  flag the task can observe.

`Pool` is the substrate for the structured combinators in
Phase 3 -- those functions do not create Runtimes directly,
they submit to a pool.

## 5. Structured combinators

Phase 3. All layer on `Pool` + `Future`:

```php
use SjI\FfiZts\Parallel\Concurrent\Structured;

/** @var array{a: int, b: int} */
$results = Structured::all([
    'a' => fn () => workA(),
    'b' => fn () => workB(),
]);

$winner = Structured::race([
    fn () => fromCache(),
    fn () => fromOrigin(),
]);

Structured::forEach(
    $items,
    fn ($item) => process($item),
    concurrency: 8,
);
```

Semantics:

- `all` -- runs all, waits for all, propagates the first
  exception (cancelling the remaining).
- `race` -- runs all, returns first success, cancels the rest.
- `forEach` -- streams items through a bounded pool; back-
  pressure honoured.

All three install a cancellation propagation scope. If the
caller abandons the result (exception above the call site),
unfinished tasks are cancelled cooperatively.

The combinator vocabulary mirrors what is familiar from other
runtimes (Go `errgroup`, JS `Promise.all` / `race`, Kotlin
`coroutineScope`). Users carry mental models across ecosystems
without relearning names.

## 6. Atomics

Phase 2. Thin FFI wrappers over C atomic intrinsics, used
internally by `Pool` (for task counts, shutdown signalling) and
exposed for user cancellation / progress flags:

```php
use SjI\FfiZts\Parallel\Concurrent\Atomic;

$cancelled = Atomic::bool(false);

$pool->submit(function () use ($cancelled) {
    for ($i = 0; $i < 1_000_000_000; $i++) {
        if ($cancelled->load()) return;
        /* work */
    }
});

// from another thread
$cancelled->store(true);
```

Operations follow Node's `Atomics` naming:
`load`, `store`, `add`, `sub`, `and`, `or`, `xor`,
`compareExchange`, and `wait` / `notify` (built on futex /
equivalent).

The backing memory for an `Atomic` lives in the core package's
Arena, allocated persistent, so the atomic is visible to every
thread by pointer.

## 7. What changes in existing code

The satellite's current entry point
(`SjI\FfiZts\Parallel\Parallel::boot()`) stays. The concurrency
primitives are additive:

- `Parallel::boot()->pool()` -- lazily create or return the
  default pool.
- `Parallel::boot()->channel(string $name)` -- open / create a
  channel.
- `parallel\Runtime` direct usage continues to work; it does not
  have to be wrapped unless the user wants payload handoff.

Existing worker scripts keep functioning. The new API is an
extra layer users opt into when they need to avoid
`igbinary_serialize` on the hot path.

## 8. Phase delivery ordering in this package

| Phase | Deliverable |
| --- | --- |
| 2.1 | `Channel` (sync, payload-aware, wraps `parallel\Channel`) |
| 2.2 | `Atomic` primitives (int32, int64, bool) |
| 2.3 | `Pool` + `Future` |
| 2.4 | `AsyncChannel` (eventfd / pipe fallback) |
| 3.1 | `Structured::all` / `race` / `forEach` |
| 3.2 | Cancellation scope + cooperative-cancel helpers |
| Later | MPMC lock-free queue, shared mmap buffer, broadcast channel |

Phase 4 / 5 (immutable array and object-graph codecs) live in
the core package. The satellite's `Channel` accepts whatever the
core package defines as a payload shape; no parallel-package
changes are required for those phases.

## 9. Open questions specific to this package

- **Pool bootstrapping cost.** Creating 8 `parallel\Runtime`s
  costs ~40-120 ms on current hardware. Worth an eager-start
  option for latency-sensitive applications.
- **Channel naming vs anonymous channels.** `parallel\Channel`
  supports named channels for cross-Runtime discovery. Our
  wrapper surfaces both; needs documentation on when to use
  which.
- **Future chain composition.** `then` / `map` / `flatMap` on
  `Future` is tempting but opens a scheduling question (which
  pool runs the continuation?). Deferred until we see concrete
  user demand.
- **Timeouts.** `Future::getWithTimeout(int $ms)` vs
  `Structured::all([...], timeout: ...)` -- pick one and apply
  uniformly.
