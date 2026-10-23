# Page and permission import

Regenerate after adding controllers or methods:

```
php spark pages:export-csv
```

Import into the master database (`MASTER_DB`) in this order:

1. `page.csv` — columns `id,controller,method`
2. `permission.csv` — columns `id,role_id,page_id,is_allowed`

phpMyAdmin: Import → CSV → check “The first line of the file contains the table column names”.

All `is_allowed` values are `1`. Role `admin` (`id=4`) bypasses the matrix in code. Later, set denied rows to `0`.
