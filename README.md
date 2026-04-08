# magnum-tag-audit-script

## Tag audit script

This repository includes `tag_audit.php`, a CLI script that audits tag/router mismatches.

### Usage

```bash
php tag_audit.php <tag_file.csv> <name_set_file.csv> [output_directory]
```

Examples:

```bash
php tag_audit.php tags.csv name_set.csv
php tag_audit.php tags.csv name_set.csv ./out
```

### Behavior

- Reads both CSV files (first row is treated as header).
- Uses `NAME (Local)` in the tag file as the video nameset name.
- Uses `NAME (Local)` / `Local` / `Global` in the name_set file as nameset matching keys (with fallback to `Port Name`), and uses `Port Name` to determine router by substring:
  - `CT => CT`
  - `DRE => CT`
  - `ITXR => CT`
  - `TOC => TOC`
  - `TPM => CT`
  - `GWY => TOC`
- Tags are checked from the 3rd column onward in the tag file.
- Writes rows to output when:
  - router is `CT` and video contains tag `TOC`, or
  - router is `TOC` and video contains tag `CT` or `QC-SRC` or `cnn-src`.
- Output filename format:
  - `YYYY-MM-DD-HH-mm-tag-audit.xlsx`
- Output is an Excel `.xlsx` file where:
  - each unique tag gets its own fixed column (for example, `CT` is always in the `CT` column)
  - offending tag cells are highlighted in yellow
- A second output file is also written:
  - `YYYY-MM-DD-HH-mm-tag-audit-fixes.csv`
  - for each flagged row, if a CT/TOC counterpart nameset exists in `name_set`:
    - writes the original row with offending tags removed
    - writes the counterpart row with offending tags added
- CSV parsing explicitly provides the `escape` argument to avoid:
  - `Deprecated: fgetcsv(): the $escape parameter must be provided as its default value`
