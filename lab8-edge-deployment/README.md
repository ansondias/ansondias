# Lab 8 — Edge Deployment (MobileNetV2 → full-integer TFLite)

**Submit:** `Lab8_Edge_Deployment.pdf` (the whole executed notebook: code, outputs, figures and the written
report) and/or `Lab8_Edge_Deployment.ipynb` (the same notebook, runnable).

| file | what it is |
|---|---|
| `Lab8_Edge_Deployment.ipynb` | the lab: conversion, FlatBuffer inspection, simulated 256 MB / 1-core device, benchmarks, trade-off surface, written report |
| `Lab8_Edge_Deployment.pdf` | PDF export of the executed notebook (for Google Classroom) |
| `edge_device_worker.py` | the program that runs *inside* the simulated device (written by the notebook) |
| `figures/` | all figures (PNG) |
| `results/` | every measured table (CSV / JSON) |

## Pipeline

1. Pre-trained Keras **MobileNetV2** (ImageNet weights for 224, 160 and 96 px), with input scaling inside the graph.
2. **TFLite conversion**: FP32, INT8 with float fallback, and **full-integer INT8** (integer-only op set, uint8
   I/O). The representative dataset is 300 Imagenette training images.
3. **FlatBuffer inspection** with the TFLite schema: per-operator dtypes, quantisation parameters, INT8 kernels vs
   float fallback. Also included: an operator-coverage probe and a float-fallback case study.
4. **Simulated edge device** using Linux cgroups: 256 MiB hard memory limit (OOM-kill), 1 CPU core (CFS quota and
   pinning), single-threaded LiteRT runtime.
5. **Latency and peak memory** at 224×224, 160×160 and 96×96, plus on-device ImageNet top-1/top-5 accuracy on all
   3,925 Imagenette validation images.
6. **Latency–accuracy–memory trade-off surface** (3-D and 2-D projections, Pareto front).
7. **Written report**: why not every TFLite operator supports INT8, and what this means for edge deployment.

## Re-running

```bash
pip install tensorflow-cpu ai-edge-litert matplotlib pandas pillow jupyter
jupyter nbconvert --to notebook --execute --inplace Lab8_Edge_Deployment.ipynb
```

The notebook downloads Imagenette (~340 MB) and the Keras weights on first run. Creating cgroups needs root on a
Linux host (cgroup v1 or a delegated v2 hierarchy). Without that, the sandbox falls back to pinning the CPU only
and marks the memory limit as *not enforced*.
