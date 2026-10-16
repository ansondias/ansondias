# Lab 9: Optical Matrix-Vector Multiplication

A NumPy simulation of a Mach-Zehnder interferometer (MZI) mesh in which the MZI phase angles are the only tunable parameters.

| File | What it is |
|---|---|
| `Lab9_Optical_Matrix_Vector_Multiplication.ipynb` | Executed notebook with theory, code, outputs and conclusions. It opens in Jupyter or Google Colab. |
| `Lab9_Optical_Matrix_Vector_Multiplication.pdf` | The same notebook as a 19-page PDF report. |

## What it covers

1. **Optical 4×4 unitary layer.** A rectangular (Clements) mesh of 6 MZIs plus 4 output phase shifters, for 16 phases in total, is programmed for a Haar-random unitary using the Clements decomposition. Light is propagated through the mesh one component at a time. The output matches `U @ x` to within about 3 to 9 machine epsilons, measured over 1,000 unitaries.
2. **Phase noise.** Gaussian errors on the MZI angles give the following results. They match a first-order prediction of √3·σ for the matrix error and 2.25·σ² for the infidelity.

   | σ | fidelity | relative matrix error | detected-power error |
   |---|---|---|---|
   | 1° | 0.9993 | 3.0% | 1.1% |
   | 2° | 0.9972 | 6.1% | 2.2% |
   | 5° | 0.983 | 15.1% | 5.6% |

3. **Two-layer optical neural network.** Two 4×4 meshes with an O-E-O |z| nonlinearity are trained by plain gradient descent on the phase angles. The gradients come from the adjoint method, which was checked against finite differences. The network reaches 100% train and test accuracy on a linearly separable dataset. Mean test accuracy is 99.5% with 5° phase noise.
4. **MAC comparison.** A digital network of the same depth and width needs 32 real MACs per inference and about 14.4 M MACs to train. The optical network uses 0 digital MACs for its matrix products, while physically performing the equivalent of 128 real MACs per sample.

## Running it

The notebook only needs NumPy and Matplotlib. It runs top to bottom in under a minute with a fixed seed:

```bash
pip install numpy matplotlib jupyter
jupyter nbconvert --to notebook --execute Lab9_Optical_Matrix_Vector_Multiplication.ipynb --output out.ipynb
```
