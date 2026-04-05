# Developer Documentation – ArbeitszeitCheck

**Version:** 1.1.0  
**Last Updated:** 2026-04-05

This guide is for developers who want to contribute to ArbeitszeitCheck or integrate with it.

---

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Development Setup](#development-setup)
3. [Code Structure](#code-structure)
4. [Database Schema](#database-schema)
5. [API Development](#api-development)
6. [Frontend Development](#frontend-development)
7. [Testing](#testing)
8. [Contributing](#contributing)
9. [Code Standards](#code-standards)
10. [Security Guidelines](#security-guidelines)
11. [Vacation carryover (Resturlaub)](#vacation-carryover-resturlaub)

---

## Architecture Overview

### Technology Stack

- **Backend:** PHP 8.1+ with Nextcloud App Framework
- **Frontend:** Vanilla JavaScript with PHP templates
- **Database:** MySQL/MariaDB, PostgreSQL, or SQLite
- **Build Tools:** None required (vanilla JS)
- **Testing:** PHPUnit

### Architecture Pattern

ArbeitszeitCheck follows Nextcloud's standard app architecture:

```
apps/arbeitszeitcheck/
├── appinfo/           # App metadata and routes
├── lib/               # PHP backend code
│   ├── Controller/    # API controllers
│   ├── Service/       # Business logic
│   ├── Db/            # Database entities and mappers
│   └── BackgroundJob/ # Background jobs
├── js/                # Vanilla JavaScript
│   ├── common/        # Common utilities and components
│   └── [page].js      # Page-specific JavaScript
├── css/               # Stylesheets
│   ├── common/        # Common styles
│   └── [page].css     # Page-specific styles
├── templates/         # PHP templates
├── tests/             # Test files
└── docs/              # Documentation
```

### Design Principles

1. **Separation of Concerns:**
   - Controllers handle HTTP requests/responses
   - Services contain business logic
   - Mappers handle database operations
   - Entities represent data models

2. **Dependency Injection:**
   - Use Nextcloud's DI container
   - Inject dependencies via constructor
   - No static dependencies

3. **Type Safety:**
   - PHP strict types enabled
   - Type hints for all parameters and returns
   - No mixed types

---

## Development Setup

### Prerequisites

- Nextcloud 32+ installed and running
- PHP 8.1+ with required extensions
- Node.js 18+ and npm
- Composer
- Git

### Initial Setup

1. **Clone repository:**
   ```bash
   cd /path/to/nextcloud/apps/
   git clone https://github.com/aSoftwareByDesignRepository/nextcloud-arbeitszeitcheck.git arbeitszeitcheck
   cd arbeitszeitcheck
   ```

2. **Install PHP dependencies:**
   ```bash
   composer install
   ```

3. **Install Node.js dependencies** (for linting and dev tooling; the app uses vanilla JS, no build step required):
   ```bash
   npm install
   ```

4. **Enable app:**
   ```bash
   php occ app:enable arbeitszeitcheck
   ```

### Development Mode

For development with hot-reload:

```bash
# Terminal 1: Watch for changes
npm run watch

# Terminal 2: Run Nextcloud
php -S localhost:8080
```

### IDE Configuration

**PHPStorm/IntelliJ:**
- Set PHP language level to 8.1
- Enable PSR-12 code style
- Configure PHPUnit for tests

**VS Code:**
- Install PHP extensions
- Configure ESLint and Prettier (optional, for JavaScript)

---

## Code Structure

### Backend Structure

#### Controllers

Controllers handle HTTP requests and return responses:

```php
<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class ExampleController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     */
    public function index(): JSONResponse
    {
        return new JSONResponse([
            'success' => true,
            'data' => []
        ]);
    }
}
```

**Controller Annotations:**
- `@NoAdminRequired` - Endpoint accessible to all authenticated users
- `@NoCSRFRequired` - JSON API endpoints use this because the CSRF check runs before the request body is decoded; the frontend still sends `requesttoken` in headers for session integrity. Use sparingly.
- `@PublicPage` - No auth required (e.g. health check for load balancers). **Security:** Never expose raw exception messages or sensitive data on PublicPage endpoints.

#### Services

Services contain business logic:

```php
<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCP\ILogger;

class TimeTrackingService
{
    private TimeEntryMapper $timeEntryMapper;
    private ILogger $logger;

    public function __construct(
        TimeEntryMapper $timeEntryMapper,
        ILogger $logger
    ) {
        $this->timeEntryMapper = $timeEntryMapper;
        $this->logger = $logger;
    }

    public function clockIn(string $userId): TimeEntry
    {
        // Business logic here
        $entry = new TimeEntry();
        $entry->setUserId($userId);
        $entry->setStartTime(new \DateTime());
        
        return $this->timeEntryMapper->insert($entry);
    }
}
```

#### Mappers

Mappers handle database operations:

```php
<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\IDBConnection;
use OCP\AppFramework\Db\QBMapper;

class TimeEntryMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'at_entries', TimeEntry::class);
    }

    public function findByUser(string $userId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->orderBy('start_time', 'DESC');
        
        return $this->findEntities($qb);
    }
}
```

#### Entities

Entities represent database rows:

```php
<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

class TimeEntry extends Entity
{
    protected string $userId = '';
    protected \DateTime $startTime;
    protected ?\DateTime $endTime = null;
    protected float $durationHours = 0.0;
    protected string $status = 'active';

    public function getSummary(): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->userId,
            'startTime' => $this->startTime->format('c'),
            'durationHours' => $this->durationHours,
            'status' => $this->status
        ];
    }
}
```

### Frontend Structure

#### PHP Templates

Templates render data server-side using PHP:

```php
<?php
// templates/example.php
use OCP\Util;

Util::addScript('arbeitszeitcheck', 'common/utils');
Util::addScript('arbeitszeitcheck', 'example');
Util::addStyle('arbeitszeitcheck', 'example');
?>

<div id="app-content">
    <?php foreach ($_['items'] as $item): ?>
        <div class="item-card">
            <h3><?php p($item['name']); ?></h3>
            <button type="button" class="button primary" data-item-id="<?php p($item['id']); ?>">
                <?php p($l->t('Click me')); ?>
            </button>
        </div>
    <?php endforeach; ?>
</div>
```

#### Vanilla JavaScript

JavaScript handles interactions and AJAX updates:

```javascript
// js/example.js
(function() {
    'use strict';

    const Utils = window.ArbeitszeitCheckUtils || {};
    const Messaging = window.ArbeitszeitCheckMessaging || {};

    function init() {
        bindEvents();
    }

    function bindEvents() {
        const buttons = Utils.$$('.button[data-item-id]');
        buttons.forEach(btn => {
            Utils.on(btn, 'click', handleClick);
        });
    }

    function handleClick(e) {
        const itemId = e.target.dataset.itemId;
        
        Utils.ajax('/apps/arbeitszeitcheck/api/example/' + itemId, {
            method: 'GET',
            onSuccess: function(data) {
                if (data.success) {
                    Messaging.showSuccess('Operation successful');
                }
            },
            onError: function(error) {
                Messaging.showError('Operation failed');
                console.error('Error:', error);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
```

---

## Database Schema

### Tables

All tables use the `at_` prefix (short for arbeitszeitcheck):

- `oc_at_entries` - Time entries
- `oc_at_absences` - Absence requests
- `oc_at_vacation_year_balance` - Per user and calendar year: opening **carryover** days (Resturlaub from prior year, as recorded for year *Y*)
- `oc_at_violations` - Compliance violations
- `oc_at_models` - Working time models
- `oc_at_user_models` - User working time model assignments
- `oc_at_settings` - User settings
- `oc_at_audit` - Audit logs

### Migrations

Migrations are in `lib/Migration/`:

```php
<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20241229000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();
        
        if (!$schema->hasTable('at_entries')) {
            $table = $schema->createTable('at_entries');
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            // ... more columns
        }
        
        return $schema;
    }
}
```

### Vacation carryover (Resturlaub)

Carryover is **not** a separate “adjustment” column: the editable opening balance is `carryover_days` on `at_vacation_year_balance` for `(user_id, year)`.

**Config (app `IConfig`, keys in `Constants.php`):**

- `vacation_carryover_expiry_month` (1–12, default `3`)
- `vacation_carryover_expiry_day` (1–31, default `31`)

For calendar year *Y*, carryover from that row may only apply to vacation working days on dates **on or before** that month/day in year *Y*. After that date, **new** requests cannot consume remaining carryover for *Y*; annual entitlement from the working time model still applies. Consumption order for **approved** vacation is **FIFO** (sort by `start_date`, then `id`), implemented in `VacationAllocationService` and used by `AbsenceService::getVacationStats` and vacation validation (including re-check on approve / auto-approve).

**CLI (initial migration from other HR systems):**

```bash
php occ arbeitszeitcheck:import-vacation-balance /path/to/balances.csv --dry-run
```

CSV columns: `user_id`, `year`, `carryover_days` (header row). Validates users exist; use `--dry-run` to preview.

**Privacy:** `UserDeletedListener` deletes all `at_vacation_year_balance` rows for the removed user id.

**Known limitations (product):** Entitlement per historical year uses the **current** working time model assignment unless extended later; concurrent pending vacation requests are not “soft reserved” in the DB—approval-time validation prevents overdraw on commit under normal use.

---

## API Development

### Adding New Endpoints

1. **Add route in `appinfo/routes.php`:**
   ```php
   ['name' => 'controller#method', 'url' => '/api/endpoint', 'verb' => 'GET']
   ```

2. **Add method in controller:**
   ```php
   /**
    * @NoAdminRequired
    */
   public function method(): JSONResponse
   {
       // Implementation
   }
   ```

3. **Document behaviour where relevant:**
   - If the endpoint is public or security‑relevant, add a short note to `README.md` or the appropriate doc in `docs/` (z. B. Rollen/Compliance)
   - Include request/response examples in code comments or tests if they are non‑obvious

### Error Handling

Always return proper HTTP status codes:

```php
try {
    // Operation
    return new JSONResponse(['success' => true], Http::STATUS_OK);
} catch (NotFoundException $e) {
    return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
} catch (\Exception $e) {
    $this->logger->error('Error', ['exception' => $e]);
    return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
}
```

---

## Frontend Development

### Using Common JavaScript Utilities

The app provides common utilities in `js/common/`:

```javascript
// DOM manipulation
const element = ArbeitszeitCheckUtils.$('#my-element');
const elements = ArbeitszeitCheckUtils.$$('.my-class');

// AJAX requests
ArbeitszeitCheckUtils.ajax('/apps/arbeitszeitcheck/api/endpoint', {
    method: 'POST',
    data: { key: 'value' },
    onSuccess: function(data) {
        // Handle success
    },
    onError: function(error) {
        // Handle error
    }
});

// Messaging
ArbeitszeitCheckMessaging.showSuccess('Operation successful');
ArbeitszeitCheckMessaging.showError('Operation failed');

// Components
ArbeitszeitCheckComponents.openModal('my-modal-id');
```

### CSS Organization

**Use BEM naming:**
```css
.arbeitszeitcheck-block {}
.arbeitszeitcheck-block__element {}
.arbeitszeitcheck-block__element--modifier {}
```

**Use CSS variables:**
```css
.arbeitszeitcheck-button {
  background: var(--color-primary);
  color: var(--color-main-text);
}
```

**Common styles are in `css/common/`:**
- `base.css` - Base styles and resets
- `components.css` - Reusable UI components
- `layout.css` - Grid and layout utilities
- `utilities.css` - Helper utility classes

### Internationalization

Use PHP translation in templates:

```php
<?php p($l->t('Hello world')); ?>
```

Add translations to `l10n/de.json` and `l10n/en.json`.

For JavaScript, use Nextcloud's translation system if needed:
```javascript
// Translations are typically handled server-side in PHP templates
// For dynamic content, use AJAX to fetch translated strings
```

---

## Testing

### PHP Unit Tests

```php
<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use PHPUnit\Framework\TestCase;

class TimeTrackingServiceTest extends TestCase
{
    public function testClockInCreatesEntry(): void
    {
        // Test implementation
        $this->assertTrue(true);
    }
}
```

Run tests:
```bash
composer test
```

### JavaScript Tests

JavaScript unit tests are run with **Vitest** (jsdom environment).

Run tests:
```bash
npm test
```

### E2E workflow tests (Playwright)

E2E tests run against a real Nextcloud instance and cover role-based workflows.

Environment variables required:
- `NC_BASE_URL` (example: `http://localhost:8081`)
- `NC_EMPLOYEE_USER` / `NC_EMPLOYEE_PASS`
- `NC_MANAGER_USER` / `NC_MANAGER_PASS`
- `NC_ADMIN_USER` / `NC_ADMIN_PASS` (for admin-only scenarios when added)
- `NC_SUBSTITUTE_USER` / `NC_SUBSTITUTE_PASS`

Run:
```bash
npm run e2e
```

### Docker-based development (optional)

If you use a Docker Compose stack for Nextcloud (service name often `nextcloud`), run tests inside the container from the app directory under `custom_apps`:

Run PHP tests inside the Nextcloud container:
```bash
docker compose exec -T nextcloud bash -lc "cd /var/www/html/custom_apps/arbeitszeitcheck && composer test"
docker compose exec -T nextcloud bash -lc "cd /var/www/html/custom_apps/arbeitszeitcheck && composer test:unit"
docker compose exec -T nextcloud bash -lc "cd /var/www/html/custom_apps/arbeitszeitcheck && composer test:integration"
```

Run JS unit tests inside the Nextcloud container:
```bash
docker compose exec -T nextcloud bash -lc "cd /var/www/html/custom_apps/arbeitszeitcheck && npm ci && npm test"
```

Run E2E tests from your host machine (recommended) against the Dockerized Nextcloud at `http://localhost:8081`:
```bash
NC_BASE_URL="http://localhost:8081" \
NC_EMPLOYEE_USER="employee1" NC_EMPLOYEE_PASS="..." \
NC_MANAGER_USER="manager1" NC_MANAGER_PASS="..." \
NC_SUBSTITUTE_USER="substitute1" NC_SUBSTITUTE_PASS="..." \
npm run e2e
```

---

## Contributing

### Pull Request Process

1. **Fork repository**
2. **Create feature branch:**
   ```bash
   git checkout -b feature/my-feature
   ```
3. **Make changes:**
   - Follow code standards
   - Add tests
   - Update documentation
4. **Commit changes:**
   ```bash
   git commit -m "feat: Add new feature"
   ```
5. **Push and create PR:**
   ```bash
   git push origin feature/my-feature
   ```

### Commit Message Format

Follow [Conventional Commits](https://www.conventionalcommits.org/):

- `feat:` New feature
- `fix:` Bug fix
- `docs:` Documentation
- `test:` Tests
- `refactor:` Code refactoring
- `style:` Code style changes
- `chore:` Maintenance tasks

### Code Review Checklist

Before submitting PR:

- [ ] Code follows PSR-12 (PHP) / ESLint rules (JS)
- [ ] All tests passing
- [ ] New tests added for new features
- [ ] Documentation updated
- [ ] No console errors
- [ ] Accessibility verified
- [ ] CSS properly scoped
- [ ] No hardcoded colors
- [ ] Translations added

---

## Code Standards

### PHP Standards

- **PSR-12** coding style
- **Strict types** enabled (`declare(strict_types=1);`)
- **Type hints** for all parameters and returns
- **PHPDoc** comments for all public methods
- **No mixed types**

### JavaScript Standards

- **ESLint** with strict configuration (optional)
- **Vanilla JavaScript** - no frameworks required
- **IIFE pattern** for code isolation
- **No console.log** in production code
- **Proper error handling**
- **Use common utilities** from `js/common/`

### CSS Standards

- **BEM naming** convention
- **Scoped styles** only
- **CSS variables** for colors
- **No !important** (unless documented)

---

## Security Guidelines

### Input Validation

Always validate and sanitize input:

```php
public function create(string $date, float $hours): JSONResponse
{
    // Validate date format
    $dateObj = \DateTime::createFromFormat('Y-m-d', $date);
    if (!$dateObj) {
        throw new \InvalidArgumentException('Invalid date format');
    }
    
    // Validate hours
    if ($hours < 0 || $hours > 24) {
        throw new \InvalidArgumentException('Hours must be between 0 and 24');
    }
    
    // Continue with validated data
}
```

### Authorization Checks

Always check permissions:

```php
public function getEntry(int $id): JSONResponse
{
    $entry = $this->timeEntryMapper->find($id);
    
    // Check ownership
    if ($entry->getUserId() !== $this->userId) {
        throw new \Exception('Access denied');
    }
    
    return new JSONResponse($entry->getSummary());
}
```

### SQL Injection Prevention

Always use parameterized queries:

```php
// ✅ CORRECT
$qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

// ❌ WRONG
$qb->where($qb->expr()->eq('user_id', "'$userId'"));
```

---

## Resources

- **Nextcloud App Development:** https://docs.nextcloud.com/server/latest/developer_manual/
- **MDN Web Docs:** https://developer.mozilla.org/
- **Nextcloud App Framework:** https://docs.nextcloud.com/server/latest/developer_manual/
- **PHPUnit Documentation:** https://phpunit.de/
- **Jest Documentation:** https://jestjs.io/

---

**Last Updated:** 2025-12-29
