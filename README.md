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
- Uses `Port Name` in the name_set file to determine router by substring:
  - `CT => CT`
  - `DRE => CT`
  - `ITXR => CT`
  - `TOC => TOC`
  - `TPM => CT`
  - `GWY => TOC`
- Tags are checked from the 3rd column onward in the tag file.
- Writes rows to output when:
  - router is `CT` and video contains tag `TOC`, or
  - router is `TOC` and video contains tag `CT`.
- Output filename format:
  - `YYYY-MM-DD-HH:mm-tag-audit.csv`
