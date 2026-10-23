# Third-party files in PRIME Image Lab

These are used only when a visitor taps "Remove the background" or "Make it bigger and clearer" on a small photo, and they run in the visitor's browser. Nothing is sent to any server.

| File | What it is | Licence | Source |
|---|---|---|---|
| `assets/vendor/onnxruntime-web/ort.wasm.min.js`, `ort-wasm-simd-threaded.mjs`, `ort-wasm-simd-threaded.wasm` | ONNX Runtime Web 1.30.0 (WebAssembly engine, about 14 MB) | MIT, Copyright (c) Microsoft Corporation | npm package `onnxruntime-web` 1.30.0 |
| `assets/models/realesr-general-x4v3.onnx` | Real-ESRGAN "general x4v3" 4x super-resolution model (about 4.9 MB) | BSD-3-Clause, Copyright (c) Xintao Wang | https://github.com/xinntao/Real-ESRGAN |
| `assets/models/u2netp.onnx` | U²-Net-P salient-object model (about 4.6 MB) | Apache-2.0, Xuebin Qin et al. | https://github.com/xuebinqin/U-2-Net |

`u2netp.onnx` was taken from the npm package `@rmbg/model-u2netp` 0.0.1. Its MD5 is `8e83ca70e441ab06c318d82300c84806` and SHA-256 `309c8469258dda742793dce0ebea8e6dd393174f89934733ecc8b14c76f4ddd8`. The MD5 matches the value published for the U²-Net-P ONNX file by the rembg project; compare it against the original release before relying on the provenance.

Not used, on purpose: `@imgly/background-removal` (AGPL or commercial licence) and BRIA RMBG weights (non-commercial licence).

`realesr-general-x4v3.onnx` was built from the official weights `realesr-general-x4v3.pth` (release v0.2.5.0 of Real-ESRGAN, 4,885,111 bytes) by re-creating the SRVGGNetCompact network (34 convolutions, PReLU, pixel shuffle x4, plus the nearest-neighbour base) in ONNX with the same weights, no retraining. MD5 of the ONNX file: `30cc75f447e839e3a9bf1eeef20bf31a`.
