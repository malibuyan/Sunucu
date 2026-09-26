#!/bin/bash
# kur.sh ve telefona yapıştırılacak blok üretilir
set -euo pipefail
cd "$(dirname "$0")"
mkdir -p dist
tar --owner=0 --group=0 -czf /tmp/yuk.tgz src
python3 - <<'PY'
import base64, textwrap
yuk = "\n".join(textwrap.wrap(base64.b64encode(open('/tmp/yuk.tgz','rb').read()).decode(), 76))
s = open('kur.sh.in').read().replace('__YUK__', yuk)
open('dist/kur.sh','w').write(s)
PY
chmod +x dist/kur.sh
gzip -9 -c dist/kur.sh > /tmp/kur.gz
{
  echo "cat > /tmp/kur.b64 <<'SON'"
  base64 -w 76 /tmp/kur.gz
  echo "SON"
  echo 'base64 -d /tmp/kur.b64 | gunzip > /tmp/kur.sh && sudo bash /tmp/kur.sh'
} > dist/yapistir.txt
bash -n dist/kur.sh
echo "kur.sh: $(wc -c < dist/kur.sh) bayt, yapistir.txt: $(wc -l < dist/yapistir.txt) satır / $(wc -c < dist/yapistir.txt) bayt"
