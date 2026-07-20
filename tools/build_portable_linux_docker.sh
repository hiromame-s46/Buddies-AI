#!/usr/bin/env bash
set -Eeuo pipefail

# Run on macOS/Linux with Docker. The manylinux2014 image targets glibc 2.17.
OUT_DIR="${PWD}/portable-output"
mkdir -p "${OUT_DIR}"

docker run --rm -v "${OUT_DIR}:/output" quay.io/pypa/manylinux2014_x86_64 /bin/bash -lc '
  set -Eeuo pipefail
  yum -y install git zip >/dev/null
  /opt/python/cp311-cp311/bin/python -m pip install --quiet cmake
  export PATH="/opt/python/cp311-cp311/bin:${PATH}"
  git clone --depth=1 https://github.com/ggml-org/llama.cpp.git /tmp/llama.cpp
  cmake -S /tmp/llama.cpp -B /tmp/build \
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
  cmake --build /tmp/build --config Release --target llama-cli -j2
  mkdir -p /tmp/out
  cp /tmp/build/bin/llama-cli /tmp/out/llama-cli
  chmod 755 /tmp/out/llama-cli
  {
    echo "Built: $(date -u +%FT%TZ)"
    echo "Commit: $(git -C /tmp/llama.cpp rev-parse HEAD)"
    echo "Target: manylinux2014 / glibc 2.17"
    echo
    ldd /tmp/out/llama-cli || true
    /tmp/out/llama-cli --version || true
  } > /tmp/out/BUILD-INFO.txt 2>&1
  cd /tmp/out
  zip -9 /output/llama-portable-linux-x64.zip llama-cli BUILD-INFO.txt
'
echo "Created: ${OUT_DIR}/llama-portable-linux-x64.zip"
