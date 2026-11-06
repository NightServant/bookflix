import json
from pathlib import Path

EXPECTED = Path(__file__).parent / "expected_columns.json"


def test_expected_columns_lists_what_the_service_reads() -> None:
    """Laravel's SchemaTest checks every table and column named here exists after its migrations."""
    expected = json.loads(EXPECTED.read_text())

    assert {"user_interactions", "model_versions", "media_lsa_vectors"} <= expected.keys()
    for columns in expected.values():
        assert columns
        assert all(isinstance(column, str) for column in columns)
