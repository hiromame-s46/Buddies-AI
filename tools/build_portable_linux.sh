#!/usr/bin/env bash
set -Eeuo pipefail

# Builds a low-dependency Linux x86_64 llama-cli bundle.
# Recommended environment: CentOS 7 / manylinux2014 or another old-glibc Linux.

ROOT="${PWD}/lfm-portable-build"
SRC="${ROOT}/llama.cpp"
BUILD="${ROOT}/build"
OUT="${ROOT}/out"
JOBS="${JOBS:-2}"

command -v git >/dev/null
command -v cmake >/dev/null
command -v c++ >/dev/null
command -v zip >/dev/null

rm -rf "${ROOT}"
mkdir -p "${ROOT}" "${OUT}"
git clone --depth=1 https://github.com/ggml-org/llama.cpp.git "${SRC}"

cmake -S "${SRC}" -B "${BUILD}" \
  -DCMAKE_BUILD_TYPE=Release \
  -DCMAKE_EXE_LINKER_FLAGS="-static-libgcc -static-libstdc++" \
  -DBUILD_SHARED_LIBS=OFF \
  -DGGML_STATIC=ON \
  -DGGML_NATIVE=OFF \
  -DGGML_OPENMP=OFF \
  -DGGML_BLAS=OFF \
  -DGGML_BACKEND_DL=OFF \
  -DGGML_CCACHE=OFF \
  -DLLAMA_CURL=OFF \
  -DLLAMA_BUILD_TESTS=OFF \
  -DLLAMA_BUILD_EXAMPLES=OFF \
  -DLLAMA_BUILD_SERVER=OFF \
  -DLLAMA_BUILD_TOOLS=ON

cmake --build "${BUILD}" --config Release --target llama-cli -j"${JOBS}"
cp "${BUILD}/bin/llama-cli" "${OUT}/llama-cli"
chmod 755 "${OUT}/llama-cli"
{
  echo "Built: $(date -u +%FT%TZ)"
  echo "Source commit: $(git -C "${SRC}" rev-parse HEAD)"
  echo "System: $(uname -a)"
  echo
  echo "ldd:"
  ldd "${OUT}/llama-cli" || true
  echo
  "${OUT}/llama-cli" --version || true
} > "${OUT}/BUILD-INFO.txt" 2>&1
(
  cd "${OUT}"
  zip -9 "${ROOT}/llama-portable-linux-x64.zip" llama-cli BUILD-INFO.txt
)
echo "Created: ${ROOT}/llama-portable-linux-x64.zip"
