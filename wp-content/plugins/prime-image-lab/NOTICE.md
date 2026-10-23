# Third-party files in PRIME Image Lab

These are used only when a visitor taps "Remove the background", and they run in the visitor's browser. Nothing is sent to any server.

| File | What it is | Licence | Source |
|---|---|---|---|
| `assets/vendor/onnxruntime-web/ort.wasm.min.js`, `ort-wasm-simd-threaded.mjs`, `ort-wasm-simd-threaded.wasm` | ONNX Runtime Web 1.30.0 (WebAssembly engine, about 14 MB) | MIT, Copyright (c) Microsoft Corporation | npm package `onnxruntime-web` 1.30.0 |
| `assets/models/u2netp.onnx` | U²-Net-P salient-object model (about 4.6 MB) | Apache-2.0, Xuebin Qin et al. | https://github.com/xuebinqin/U-2-Net |

`u2netp.onnx` was taken from the npm package `@rmbg/model-u2netp` 0.0.1. Its MD5 is `8e83ca70e441ab06c318d82300c84806` and SHA-256 `309c8469258dda742793dce0ebea8e6dd393174f89934733ecc8b14c76f4ddd8`. The MD5 matches the value published for the U²-Net-P ONNX file by the rembg project; compare it against the original release before relying on the provenance.

Not used, on purpose: `@imgly/background-removal` (AGPL or commercial licence) and BRIA RMBG weights (non-commercial licence).
