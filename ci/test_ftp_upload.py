"""Checks deployment ordering, validation and secret-safe failure handling."""

import io
from pathlib import Path
import tempfile
import unittest

from ci import ftp_upload


class FakeFTP:
    def __init__(self, timeout):
        self.calls = [("timeout", timeout)]
        self.failure = None

    def _record(self, method, *args):
        self.calls.append((method, *args))
        if self.failure == method:
            raise RuntimeError("server echoed secret-password and secret-user")

    def connect(self, host, port):
        self._record("connect", host, port)

    def login(self, user, password):
        self._record("login", user, password)

    def set_pasv(self, enabled):
        self._record("passive", enabled)

    def cwd(self, directory):
        self._record("cwd", directory)

    def storbinary(self, command, source):
        self._record("store", command, source.read())

    def rename(self, source, target):
        self._record("rename", source, target)

    def quit(self):
        self._record("quit")

    def close(self):
        self._record("close")


class FTPUploadTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / "index.php").write_bytes(b"<?php echo '\xc3\xb6';")
        (self.root / ".htaccess").write_bytes(b"Options -Indexes\n")
        (self.root / "z.js").write_bytes(b"const value = 1;\n")
        (self.root / "deployment_version.php").write_bytes(b"<?php echo 'commit';")
        self.environ = {
            "FTP_SERVER": "192.168.112.30",
            "FTP_USER": "secret-user",
            "FTP_PASS": "secret-password",
        }
        self.instances = []
        self.failure = None

    def factory(self, timeout):
        instance = FakeFTP(timeout)
        instance.failure = self.failure
        self.instances.append(instance)
        return instance

    def run_main(self, directory=None, environ=None):
        stdout, stderr = io.StringIO(), io.StringIO()
        code = ftp_upload.main(
            [str(self.root if directory is None else directory)],
            self.environ if environ is None else environ,
            self.factory,
            stdout,
            stderr,
        )
        return code, stdout.getvalue(), stderr.getvalue()

    def test_missing_variables_fail_before_connect(self):
        for name in ("FTP_SERVER", "FTP_USER", "FTP_PASS"):
            with self.subTest(name=name):
                environ = dict(self.environ)
                del environ[name]
                code, output, error = self.run_main(environ=environ)
                self.assertEqual(code, 1)
                self.assertIn(name, error)
                self.assertEqual(output, "")
                self.assertEqual(self.instances, [])

    def test_invalid_port_fails_before_connect(self):
        for value in ("0", "65536", "-1", "not-a-port", "22\nUSER injected", ""):
            with self.subTest(port=value):
                code, _, error = self.run_main(environ={**self.environ, "FTP_PORT": value})
                self.assertEqual(code, 1)
                self.assertIn("FTP_PORT", error)
                self.assertEqual(self.instances, [])

    def test_host_variable_replaces_ftp_server(self):
        environ = {key: value for key, value in self.environ.items() if key != "FTP_SERVER"}
        environ["HOST"] = "192.168.112.30"
        code, _, _ = self.run_main(environ=environ)
        self.assertEqual(code, 0)
        self.assertIn(("connect", "192.168.112.30", 28832), self.instances[0].calls)

    def test_explicit_ftp_server_overrides_host(self):
        code, _, _ = self.run_main(environ={**self.environ, "HOST": "192.168.112.99"})
        self.assertEqual(code, 0)
        self.assertIn(("connect", self.environ["FTP_SERVER"], 28832), self.instances[0].calls)

    def test_missing_artifact_path_fails_before_connect(self):
        code, _, _ = self.run_main(directory=self.root / "missing")
        self.assertEqual(code, 1)
        self.assertEqual(self.instances, [])

    def test_unexpected_file_fails_before_connect(self):
        (self.root / ".env").write_text("FTP_PASS=secret-password")
        code, output, error = self.run_main()
        self.assertEqual(code, 1)
        self.assertEqual(output, "")
        self.assertNotIn("secret-password", error)
        self.assertEqual(self.instances, [])

    def test_subdirectory_fails_before_connect(self):
        (self.root / "injected").mkdir()
        code, _, _ = self.run_main()
        self.assertEqual(code, 1)
        self.assertEqual(self.instances, [])

    def test_missing_marker_fails_before_connect(self):
        (self.root / "deployment_version.php").unlink()
        code, _, error = self.run_main()
        self.assertEqual(code, 1)
        self.assertIn("deployment_version.php", error)
        self.assertEqual(self.instances, [])

    def test_control_characters_in_configuration_fail_before_connect(self):
        for name in ("FTP_SERVER", "FTP_USER", "FTP_PASS", "FTP_SERVER_DIR"):
            with self.subTest(name=name):
                code, _, error = self.run_main(environ={**self.environ, name: "secret-password\nCWD /"})
                self.assertEqual(code, 1)
                self.assertNotIn("secret-password", error)
                self.assertEqual(self.instances, [])

    def test_control_characters_in_filename_are_rejected(self):
        # Windows cannot create these names, so validate through a fake directory entry.
        from unittest.mock import MagicMock, patch

        item = MagicMock()
        item.name = "injected\r\nDELE index.php"
        item.suffix = ".php"
        item.is_symlink.return_value = False
        item.is_file.return_value = True
        with patch.object(Path, "iterdir", return_value=iter([item])):
            code, _, _ = self.run_main()
        self.assertEqual(code, 1)
        self.assertEqual(self.instances, [])

    def test_passive_explicit_directory_binary_upload_atomic_rename_and_marker_last(self):
        code, output, error = self.run_main(environ={**self.environ, "FTP_SERVER_DIR": "/web", "FTP_PORT": "2121"})
        self.assertEqual(code, 0)
        self.assertEqual(error, "")
        calls = self.instances[0].calls
        self.assertEqual(calls[:5], [
            ("timeout", 30),
            ("connect", "192.168.112.30", 2121),
            ("login", "secret-user", "secret-password"),
            ("passive", True),
            ("cwd", "/web"),
        ])
        transfers = calls[5:-2]
        names = [".htaccess", "index.php", "z.js", "deployment_version.php"]
        self.assertEqual(len(transfers), 2 * len(names))
        for offset, name in enumerate(names):
            temporary = "." + name + ".upload"
            self.assertEqual(transfers[2 * offset], ("store", "STOR " + temporary, (self.root / name).read_bytes()))
            self.assertEqual(transfers[2 * offset + 1], ("rename", temporary, name))
        self.assertEqual(calls[-2:], [("quit",), ("close",)])
        self.assertIn("4 Dateien", output)
        self.assertNotIn("secret-password", output + error)
        self.assertNotIn("secret-user", output + error)

    def test_default_port_and_directory(self):
        code, _, _ = self.run_main()
        self.assertEqual(code, 0)
        calls = self.instances[0].calls
        self.assertIn(("connect", "192.168.112.30", 28832), calls)
        self.assertIn(("cwd", "."), calls)

    def test_failures_stop_deployment_without_secret_output(self):
        for operation in ("connect", "login", "passive", "cwd", "store", "rename", "quit"):
            with self.subTest(operation=operation):
                self.failure = operation
                code, output, error = self.run_main()
                self.assertEqual(code, 1)
                self.assertEqual(output, "")
                self.assertIn("fehlgeschlagen", error)
                self.assertNotIn("secret-password", error)
                self.assertNotIn("secret-user", error)
                calls = self.instances[-1].calls
                self.assertEqual(calls[-1], ("close",))
                if operation not in ("quit",):
                    self.assertNotIn(("rename", ".deployment_version.php.upload", "deployment_version.php"), calls)


if __name__ == "__main__":
    unittest.main()
