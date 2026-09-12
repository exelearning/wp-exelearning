# eXeLearning testing

- PHPUnit's `@covers` **discards every line executed outside the classes it
  names**, so code can report 0% while a passing test exercises it on every run.
  When a test drives a collaborator on purpose, name it too — the annotation
  accepts several. When the subject is not a class at all (a view under
  `admin/views/`, for one), leave the annotation off and say why in the
  docblock, as `tests/unit/EditorBootstrapPageTest.php` does. Read the per-file
  numbers, not only the total: a line that stays uncovered while a green test
  runs through it is almost always attribution, not a missing test — and
  chasing it with a new test writes a test for code that was already covered.

Use the committed Composer scripts and `.phpcs.xml.dist`. The architecture checker is `make architecture-check`.
