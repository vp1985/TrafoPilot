from pathlib import Path
import unittest


class AcceptanceClaims(unittest.TestCase):
    def test_final_independent_review_passed_without_promotion_overclaim(self):
        report = (Path(__file__).parents[1] / 'docs/lexware-history-acceptance-2026-10-03.md').read_text()
        self.assertNotIn('No implementation blocker remains', report)
        self.assertIn('Final independent review: passed', report)
        self.assertNotIn('Final independent review: pending', report)
        self.assertIn('does not authorize production', report)
        self.assertNotIn('document types including dunnings', report)
        self.assertNotIn('34 isolated history/conflict database cases', report)


if __name__ == '__main__':
    unittest.main()
