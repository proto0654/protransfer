# Protransfer

Transfer landing with a WordPress content and calculator backend. Project instructions override global instructions. Project documentation is English by default; retain the language of existing Russian documents.

- Context: [docs/CONTEXT.md](docs/CONTEXT.md)
- Documentation: [docs/README.md](docs/README.md)
- `npm run dev`: local Vite, proxying WordPress API to staging.
- `npm run build` and `npm run build:weblaba`: build static and WordPress landing variants.
- `node --test scripts/*.test.js`, `php scripts/calculator-admin.test.php` and `php scripts/landing-content.test.php`, `php scripts/point-editor.test.php`, `php scripts/point-migration.test.php` and `php scripts/calculator-lab.test.php`: calculator, content and point checks; require Node and PHP.
- Never deploy or run production migrations without an explicit request. Do not commit credentials or ignored runtime files.
