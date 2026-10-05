"""Release regressions: malicious packages, mismatched versions and rollback attempts."""
import importlib.util
from pathlib import Path
import stat
import tempfile
import unittest
import warnings
import zipfile

MODULE = Path(__file__).resolve().parents[1] / "scripts/wordpress_release.py"
spec = importlib.util.spec_from_file_location("wordpress_release", MODULE)
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


class ReleaseTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.archive = Path(self.temp.name) / "plugin.zip"
        self.files = {
            "marche-potier.php": b"<?php\n/*\nVersion: 0.19.4\nText Domain: poterie-navarraise-market-manager\n*/\n",
            "readme.txt": b"=== Plugin ===\nStable tag: 0.19.4\n",
            "includes/example.php": b"<?php\n",
            "assets/example.css": b"body {}\n",
        }

    def package(self, extra=()):
        with zipfile.ZipFile(self.archive, "w") as archive:
            for name, content in self.files.items():
                archive.writestr(f"{release.SLUG}/{name}", content)
            for name, content in extra:
                archive.writestr(name, content)
        return release.read_package(self.archive, "0.19.4")

    def test_valid_archive_preserves_bytes(self):
        self.assertEqual(self.package(), self.files)

    def test_wrong_root_rejected(self):
        with self.assertRaises(ValueError):
            self.package([("other/plugin.php", b"")])

    def test_traversal_rejected_before_writing(self):
        with self.assertRaises(ValueError):
            self.package([(f"{release.SLUG}/assets/../../outside", b"")])
        self.assertFalse((Path(self.temp.name) / "outside").exists())

    def test_backslash_rejected(self):
        # ZipFile normalizes Windows separators when writing; corrupt both stored names afterward.
        self.package([(f"{release.SLUG}/assets/outside", b"")])
        self.archive.write_bytes(self.archive.read_bytes().replace(b"/assets/outside", b"/assets\\outside"))
        with self.assertRaises(ValueError):
            release.read_package(self.archive, "0.19.4")

    def test_duplicate_rejected(self):
        with warnings.catch_warnings():
            warnings.simplefilter("ignore", UserWarning)
            with self.assertRaises(ValueError):
                self.package([(f"{release.SLUG}/readme.txt", self.files["readme.txt"])])

    def test_case_collision_rejected(self):
        with self.assertRaises(ValueError):
            self.package([(f"{release.SLUG}/includes/Example.php", b"")])

    def test_symlink_rejected(self):
        entry = zipfile.ZipInfo(f"{release.SLUG}/assets/link")
        entry.create_system = 3
        entry.external_attr = (stat.S_IFLNK | 0o777) << 16
        with self.assertRaises(ValueError):
            self.package([(entry, b"/etc/passwd")])

    def test_development_and_hidden_files_rejected(self):
        for name in ("tests/test.php", ".git/config", "assets/.env"):
            with self.subTest(name=name), self.assertRaises(ValueError):
                self.package([(f"{release.SLUG}/{name}", b"")])

    def test_header_mismatch_rejected(self):
        for name, old, new in (("readme.txt", b"0.19.4", b"0.19.5"),
                               ("marche-potier.php", b"0.19.4", b"0.19.5"),
                               ("marche-potier.php", release.SLUG.encode(), b"wrong-slug")):
            previous = self.files[name]
            self.files[name] = previous.replace(old, new)
            with self.subTest(name=name), self.assertRaises(ValueError):
                self.package()
            self.files[name] = previous

    def test_changed_file_and_missing_file_rejected(self):
        for other in ({**self.files, "assets/example.css": b"modified"}, {"readme.txt": b""}):
            with self.assertRaises(ValueError):
                release.same_files(other, self.files)

    def test_numeric_versions_and_invalid_input(self):
        self.assertEqual(release.release_version("v0.19.4"), "0.19.4")
        for value in ("v0.19.4-beta", "main", "v01.19.4", "v0.19.4\nfoo", "v0.19.4;id"):
            with self.subTest(value=value), self.assertRaises(ValueError):
                release.release_version(value)

    def test_upgrade_initial_release_and_numeric_sort(self):
        release.check_advance("0.19.4", [])
        release.check_advance("0.19.10", ["0.19.4", "0.19.9"], "0.19.9")

    def test_downgrade_or_overwrite_rejected(self):
        for tags, stable in ((["0.19.5"], None), ([], "0.19.5"), (["0.19.4"], "0.19.4")):
            with self.subTest(tags=tags, stable=stable), self.assertRaises(ValueError):
                release.check_advance("0.19.4", tags, stable)


if __name__ == "__main__":
    unittest.main()
