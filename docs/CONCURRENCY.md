# ffi-zts-parallel concurrency design

Status: **Draft** -- satellite-specific design sketch for the
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

> **This document is a Phase 2 / 3 sketch.** The Phase 1 primitives
> in the core package (Arena, Payload, CvInjector) are the only
> design-frozen surface; everything described below is expected
> to move as Phase 1 is implemented and measured. Specific
> quantitative claims in this document (message sizes, bootstrap
> costs, throughput) are target values pending measurement.

This document covers only the parts that are specific to moving
payloads between OS threads via `parallel\Runtime`.

## 1. What this package adds on top of `parallel`

Raw `parallel\Channel::send()` serialises its argument through
PHP's serialisation (igbinary if loaded, native otherwise). For
large payloads that dominates wall time. The satellite wraps
`parallel\Channel` so that sending a `Payload` transmits only a
small header (pointer + length + arena id), and receiving
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

`parallel\Channel` carries this by value. The cost of its
serialisation is expected to be negligible relative to the
payload body handoff, but the margin is pending measurement in
the Phase 1 PoC.

## 2. Synchronous `Channel` (sketch)

Phase 2 entry-level API. Wraps a `parallel\Channel` with
payload-aware send and receive:

```php
use SjI\FfiZts\Parallel\Concurrent\Channel;

$ch = Channel::open('jobs');

// producer
/**
 * @param        Payload<Fresh>   $p
 * @param-out    Payload<Drained> $p
 */
$ch->send(Payload $p): void;

// consumer (inside a parallel\Runtime worker)
$ch->recvInto(string $cvName): void;
```

Under the hood:

1. `send()` calls `$payload->take()` which drains the handle
   and returns a raw pointer. `take()` runs the consumed-state
   check (`ptr === null` -> `ConsumedPayloadException`); it does
   not attempt runtime aliasing detection (see core
   `safety.md` \u00a72.3).
2. The pointer is packed into the `handoff_msg` struct and sent
   through the underlying `parallel\Channel`.
3. On the receiving side, the struct arrives, and the
   `CvInjector` writes a `zend_string*` zval into the CV named
   `$cvName` in the caller's frame. The caller of `recvInto`
   must be the direct synchronous owner of that CV -- see core
   `api.md` \u00a72.5 for the applicability constraint and the
   alternative Injectors for non-synchronous receive.
4. Receiver's CV dtor fires on scope exit; `pefree` releases
   the persistent zstr.

`Channel` is the default for the common case where the consumer
blocks on receipt until something arrives. It integrates cleanly
with existing `parallel` code that expects blocking channels.

Open: send-side backpressure policy (block / error / drop) is
not fixed by this sketch; the dedicated `BACKPRESSURE.md`
companion in the core package is where it will be nailed down.

## 3. `AsyncChannel` (sketch)

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

The inner `process($buf)` body runs in a deferred callback, so
`tryRecvInto` here uses an alternative Injector (static
property or holder object) rather than CV injection. See the
core `payload.md` \u00a74.1 -- the `while` loop above is
intentionally structured to keep the injection synchronous by
doing the inject inside `onReadable`'s callback and then
queuing the processing separately.

Implementation sketch:

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
via an adjacent RFC; we track these peripherally but do not
depend on any specific proposal landing), a `LibUvAsyncChannel`
or similar adapter can be added that registers the channel's
`uv_async_t` with the shared reactor directly. The user-visible
`AsyncChannel` interface stays stable; only the implementation
switches. See the core package's `limits.md` \u00a73.2 for
context.

## 4. `Pool` (sketch)

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

- Runtimes are created lazily on first `submit()` up to the
  pool size. An eager-start mode is likely needed for
  latency-sensitive callers (open question, \u00a79).
- Each worker runs an internal dispatch loop that receives
  tasks via the pool's private `Channel`.
- `bindings` are applied to the worker's task closure via
  CV injection immediately before the closure runs.
- `submit()` returns a `Future`; `get()` blocks, `tryGet()` is
  non-blocking, `cancel()` sets a cooperative cancellation
  flag the task can observe. `cancel()` does not preempt
  running code -- see core `limits.md` \u00a72.1.

`Pool` is the substrate for the structured combinators in
Phase 3 -- those functions do not create Runtimes directly,
they submit to a pool.

`Pool::shutdown()` drains the pending queue and joins the
workers. Because `parallel\Runtime` destruction will execute
any scheduled-but-unstarted tasks before the runtime exits,
shutdown semantics need careful sequencing; the precise
ordering is pending the Phase 2 implementation.

## 5. Structured combinators (sketch)

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

Intended semantics (subject to the cancellation story in
`ERROR_MODEL.md`):

- `all` -- runs all, waits for all, propagates the first
  exception. Remaining tasks are **cooperatively** cancelled --
  the cancellation flag is set but running tasks must reach a
  check point to stop. Tasks inside internal functions are not
  interruptable.
- `race` -- runs all, returns first success, cooperatively
  cancels the rest under the same caveat.
- `forEach` -- streams items through a bounded pool. Backpressure
  policy is pending `BACKPRESSURE.md`.

All three install a cancellation propagation scope. If the
caller abandons the result (exception above the call site),
unfinished tasks are cancelled cooperatively. Users who need
a hard deadline should combine this with a timer that calls
`Runtime::kill()` explicitly -- which is not a clean abort,
but is the strongest stop available.

The combinator vocabulary mirrors what is familiar from other
runtimes (Go `errgroup`, JS `Promise.all` / `race`, Kotlin
`coroutineScope`). Users carry mental models across ecosystems
without relearning names.

## 6. Atomics (sketch)

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

| Phase | Deliverable | Status |
| --- | --- | --- |
| 2.1 | `Channel` (sync, payload-aware, wraps `parallel\Channel`) | *sketch* |
| 2.2 | `Atomic` primitives (int32, int64, bool) | *sketch* |
| 2.3 | `Pool` + `Future` | *sketch* |
| 2.4 | `AsyncChannel` (eventfd / pipe fallback) | *sketch* |
| 3.1 | `Structured::all` / `race` / `forEach` | *sketch* |
| 3.2 | Cancellation scope + cooperative-cancel helpers | *sketch* |
| Later | MPMC lock-free queue, shared mmap buffer, broadcast channel | *sketch* |

Phase 4 / 5 (immutable array and object-graph codecs) live in
the core package. The satellite's `Channel` accepts whatever the
core package defines as a payload shape; no parallel-package
changes are required for those phases.

## 9. Open questions specific to this package

- **Pool bootstrapping cost.** Creating N `parallel\Runtime`s
  pays a one-time cost per runtime (TSRM alloc, opcache init,
  user-land bootstrap). The magnitude on current hardware is
  pending measurement; an eager-start option likely matters for
  latency-sensitive callers either way.
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
  uniformly. The `ERROR_MODEL.md` companion is where this will
  be decided.
- **Worker-crash handling.** A `parallel\Runtime` thread that
  hits a fatal error takes the process down with it. Pool-level
  mitigation (restart policy, poison-pill detection) is
  tractable but needs its own design before the Pool API can
  commit to it.
