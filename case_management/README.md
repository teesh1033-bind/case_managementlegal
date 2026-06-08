Case Management — HTML -> PHP conversion and DB setup

What I added

- `inc/db.php` — PDO connection; edit DB credentials for your environment.
- `inc/sidebar.php` — shared sidebar navigation include for pages.
- `inc/footer.php` — shared script includes and closing </body></html>.
- `tools/convert_html_to_php.php` — CLI PHP script that:
  - copies `pages/*.html` to `pages/*.php`,
  - updates internal hrefs from `.html` to `.php`,
  - replaces the first <aside>...</aside> block with an include to `inc/sidebar.php`,
  - injects `require_once __DIR__ . '/../inc/db.php';` once near the top,
  - inserts `include __DIR__ . '/../inc/footer.php';` before </body>.
  The script writes new `.php` files and does not delete `.html` originals.

- `sql/schema.sql` — CREATE TABLE statements and a couple of sample rows ready for import into phpMyAdmin.

How to run (on your Windows dev machine)

1) Edit DB credentials in `inc/db.php` if needed.

2) Run the conversion script from the project root with PHP CLI:

```powershell
php tools/convert_html_to_php.php
```

This will create `pages/*.php` files. Inspect a few (for example `pages/dashboard.php`) to ensure the includes are placed correctly.

3) Import the SQL into phpMyAdmin:
- Open phpMyAdmin, create or select a database, then import `sql/schema.sql`.

4) After import, start using the converted pages. The pages currently include `inc/db.php` so they will attempt DB connection when loaded.

Next steps I can help with (pick one):
- Convert pages and then wire the backend CRUD (list clients, add client form, etc.) — I'll implement minimal dynamic endpoints.
- Run further cleanup and remove `.html` originals after you verify the `.php` files work.
- Create authentication (login/logout) and protect pages.

If you'd like me to proceed with wiring a specific feature (for example "clients list and add new client"), tell me which screen to start with and I'll implement the PHP page, queries, and simple forms. 
