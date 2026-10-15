# Lab 7: Spiking Neural Network on N-MNIST (Brian2)

`Lab7_SNN_NMNIST.ipynb` is the full lab submission. It contains:

* a three-layer spiking network written in **Brian2**:
  * a rate-coded input layer (Poisson spike rate proportional to pixel intensity)
  * a hidden recurrent layer: LIF neurons with adaptive thresholds, recurrent lateral inhibition, and STDP-trained recurrent excitation
  * an output winner-take-all layer trained with teacher-guided STDP
* 20 epochs of online STDP on **N-MNIST**, with accuracy measured on a balanced **500-image test subset**
* an equivalent **PyTorch ANN** (1156-100-10), trained on the same images for the same number of epochs
* a comparison of **total spike count** (the energy proxy) against the ANN's activations and MACs, plus a 45 nm energy estimate
* **accuracy vs spike count** plots (sweeps over presentation time and input rate), confusion matrices and a discussion

## How to run (Google Colab)

1. Open the notebook in Colab and choose **Runtime → Run all**.
   The first cells install Brian2 and download N-MNIST from Mendeley Data (roughly 1 GB in total).
2. A full run takes roughly 30–60 minutes on a CPU runtime. Set `QUICK = True` in the config cell for a few-minute smoke test.
3. Download the executed notebook (**File → Download → .ipynb**) and submit it. It then contains all the outputs and plots.

If the dataset download is blocked, download `Train.zip` and `Test.zip` from
<https://www.garrickorchard.com/datasets/n-mnist> and put them in `./data/nmnist/`.
