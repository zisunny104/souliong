"""隔離 apt／PHP，驗證互動安裝引導；不修改系統套件。"""
import os
import pathlib
import pty
import select
import subprocess
import tempfile
import time

source = (pathlib.Path(__file__).resolve().parent.parent / 'deploy.sh').read_text()
functions = source[source.index('ffmpeg_ready() {'):source.index('run_selfcheck() {')]
for answer, failed in [('y', False), ('n', False), ('y', True)]:
    with tempfile.TemporaryDirectory(prefix='ffmpeg-guide-') as directory:
        root = pathlib.Path(directory)
        (root / 'bin').mkdir()
        stubs = {
            'php': '#!/bin/bash\n[ -f "$GUIDE_TEST/installed" ]\n',
            'id': '#!/bin/bash\necho 0\n',
            'apt-get': '#!/bin/bash\necho "$*" >> "$GUIDE_TEST/calls"\n[ "$GUIDE_FAIL" != 1 ] || exit 1\n[ "$1" != install ] || touch "$GUIDE_TEST/installed"\n',
        }
        for name, body in stubs.items():
            path = root / 'bin' / name
            path.write_text(body)
            path.chmod(0o755)
        script = root / 'check.sh'
        script.write_text('set -e\nHAS_PHP=1\nstep(){ echo "$*"; }\nok(){ echo "OK $*"; }\nwarn(){ echo "WARN $*"; }\n' + functions + '\ncheck_ffmpeg\n' + ('check_ffmpeg\n' if answer == 'y' and not failed else ''))
        master, slave = pty.openpty()
        environment = dict(os.environ, PATH=str(root / 'bin') + ':' + os.environ['PATH'], GUIDE_TEST=directory, GUIDE_FAIL=str(int(failed)))
        process = subprocess.Popen(['bash', str(script)], stdin=slave, stdout=slave, stderr=slave, env=environment)
        os.close(slave)
        os.write(master, (answer + '\n').encode())
        output = b''
        deadline = time.monotonic() + 10
        try:
            while time.monotonic() < deadline:
                try:
                    if select.select([master], [], [], .1)[0]:
                        output += os.read(master, 8192)
                except OSError:
                    break
            process.wait(timeout=1)
        finally:
            if process.poll() is None:
                process.kill()
                process.wait()
            os.close(master)
        assert process.returncode == 0, output.decode()
        calls = (root / 'calls').read_text().splitlines() if (root / 'calls').exists() else []
        if answer == 'n':
            assert calls == [], calls
        elif failed:
            assert calls == ['update'], calls
            assert '安裝未完成' in output.decode()
        else:
            assert calls == ['update', 'install -y ffmpeg'], calls
            assert 'FFmpeg 已安裝' in output.decode()
print('PASS: 互動確認安裝、複查且不重複安裝、取消不執行、安裝失敗不阻擋原檔上傳（隔離模擬）')
