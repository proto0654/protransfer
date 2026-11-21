# Protransfer

Transfer landing with a WordPress content and calculator backend. Project instructions override global instructions. Project documentation is English by default; retain the language of existing Russian documents.

- Context: [docs/CONTEXT.md](docs/CONTEXT.md)
- Documentation: [docs/README.md](docs/README.md)
- `npm run dev`: local Vite, proxying WordPress API to staging.
- `npm run build` and `npm run build:weblaba`: build static and WordPress landing variants.
- Tests: `node --test scripts/*.test.js` and `php scripts/*.test.php` cover the calculator, landing content, points, settings navigation and order intake; require Node and PHP.
- Never deploy or run production migrations without an explicit request. Do not commit credentials or ignored runtime files.
