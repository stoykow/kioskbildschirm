import contextlib
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

import package
import verify


COMMIT = "a" * 40


class PackageTests(unittest.TestCase):
    def test_web_package_excludes_secrets_and_server_configuration(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            for name in ("index.php", "config.php", ".htaccess", "kiosk.css", "abfahrten.js", ".env", "schema.sql", "README.md"):
                (root / name).write_text("fixture", encoding="utf-8")
            (root / "docker").mkdir()
            (root / "docker" / ".env").write_text("FTP_PASS=secret", encoding="utf-8")
            destination = root / "upload" / "htdocs"
            package.build_package(root, destination, COMMIT)
            self.assertEqual(
                {path.name for path in destination.iterdir()},
                {"index.php", "config.php", ".htaccess", "kiosk.css", "abfahrten.js", "deployment_version.php"},
            )
            marker = (destination / "deployment_version.php").read_text(encoding="utf-8")
            self.assertIn(COMMIT, marker)
            self.assertNotIn("secret", marker)

    def test_invalid_commit_cannot_create_php_marker(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            with self.assertRaises(ValueError):
                package.build_package(root, root / "upload", "'; phpinfo(); '")
            self.assertFalse((root / "upload").exists())


class LiveVerificationTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.artifact = Path(self.directory.name)
        (self.artifact / "kiosk.css").write_bytes(b"body {color: white}")
        self.responses = {
            "deployment_version.php": json.dumps({"commit": COMMIT}).encode(),
            "": b'<div id="weather"></div><div id="train"></div><div id="tram"></div><div id="bus"></div>',
            "kiosk.css": b"body {color: white}",
            "cron_abfahrten.php": b"OK\n",
            "abfall_api.php": b'{"events":[]}',
            "termine_sonstige_api.php": b'{"events":[]}',
            "aufgaben_api.php": b'{"tasks":[]}',
        }
        for kind in ("zug", "ersatzverkehr", "tram", "bus"):
            self.responses[f"abfahrten.php?typ={kind}&limit=6"] = b"[]"

    def run_verification(self):
        def read_fixture(base, path, commit, timeout=30):
            return self.responses[path]

        with patch.object(verify, "read", side_effect=read_fixture), contextlib.redirect_stdout(io.StringIO()):
            verify.verify("http://example.test/", COMMIT, self.artifact, True)

    def test_empty_departure_lists_are_valid(self):
        self.run_verification()

    def test_previous_release_is_rejected(self):
        self.responses["deployment_version.php"] = json.dumps({"commit": "b" * 40}).encode()
        with self.assertRaisesRegex(ValueError, "andere Version"):
            self.run_verification()

    def test_stale_assets_are_rejected(self):
        self.responses["kiosk.css"] = b"old stylesheet"
        with self.assertRaisesRegex(ValueError, "abweichende Datei"):
            self.run_verification()

    def test_partial_import_failure_is_rejected_even_with_ok_line(self):
        self.responses["cron_abfahrten.php"] = b"Abruf fehlgeschlagen fuer Haltestelle 977198\nOK\n"
        with self.assertRaisesRegex(ValueError, "Import"):
            self.run_verification()

    def test_api_error_object_is_rejected(self):
        self.responses["aufgaben_api.php"] = b'{"error":"DB connect failed"}'
        with self.assertRaisesRegex(ValueError, "API-Antwort"):
            self.run_verification()

    def test_regional_bus_in_city_bus_api_is_rejected(self):
        self.responses["abfahrten.php?typ=bus&limit=6"] = b'[{"linie":"67","produkt":"bus","anzeige_zeit":"10:00"}]'
        with self.assertRaisesRegex(ValueError, "unerlaubte Linie"):
            self.run_verification()

    def test_b_and_n_are_accepted(self):
        self.responses["abfahrten.php?typ=bus&limit=6"] = b'[{"linie":"B","produkt":"bus","anzeige_zeit":"10:00"},{"linie":"N","produkt":"bus","anzeige_zeit":"20:00"}]'
        self.run_verification()

    def test_credentials_in_app_url_are_rejected(self):
        with self.assertRaises(ValueError):
            verify.base_url("http://user:password@example.test/")

    def test_application_url_uses_host_and_kiosk_port(self):
        self.assertEqual(verify.application_url({"HOST": "192.168.112.30"}), "http://192.168.112.30:28830/")

    def test_explicit_application_url_overrides_host(self):
        self.assertEqual(verify.application_url({"HOST": "192.168.112.30", "APP_URL": "https://kiosk.example.test"}), "https://kiosk.example.test/")


if __name__ == "__main__":
    unittest.main()
