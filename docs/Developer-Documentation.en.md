# Developer Documentation – ArbeitszeitCheck

**Version:** 1.0.0  
**Last Updated:** 2025-12-29

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

---

## Architecture Overview

### Technology Stack

- **Backend:** PHP 8.1+ with Nextcloud App Framework
- **Frontend:** Vue.js 3 with @nextcloud/vue components
- **Database:** MySQL/MariaDB, PostgreSQL, or SQLite
- **Build Tools:** Webpack, npm
- **Testing:** PHPUnit, Jest, Vue Test Utils

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
├── src/               # Frontend Vue.js code
│   ├── views/         # Page components
│   ├── components/    # Reusable components
│   └── styles/        # CSS styles
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

- Nextcloud 27+ installed and running
- PHP 8.1+ with required extensions
- Node.js 18+ and npm
- Composer
- Git

### Initial Setup

1. **Clone repository:**
   ```bash
   cd /path/to/nextcloud/apps/
   git clone https://github.com/nextcloud/arbeitszeitcheck.git
   cd arbeitszeitcheck
   ```

2. **Install PHP dependencies:**
   ```bash
   composer install
   ```

3. **Install Node.js dependencies:**
   ```bash
   npm install
   ```

4. **Build frontend:**
   ```bash
   npm run build
   ```

5. **Enable app:**
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
- Install Vue.js extensions
- Configure ESLint and Prettier

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
- `@NoCSRFRequired` - Skip CSRF check (use sparingly)
- `@PublicPage` - Public endpoint (no auth required)

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

#### Vue Components

```vue
<template>
  <div class="timetracking-example">
    <NcButton @click="handleClick">
      {{ $t('arbeitszeitcheck', 'Click me') }}
    </NcButton>
  </div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

export default {
  name: 'ExampleComponent',
  components: {
    NcButton
  },
  methods: {
    async handleClick() {
      try {
        const response = await axios.get(generateUrl('/apps/arbeitszeitcheck/api/example'))
        console.log(response.data)
      } catch (error) {
        console.error('Error:', error)
      }
    }
  }
}
</script>

<style scoped>
.timetracking-example {
  padding: var(--default-grid-baseline);
}
</style>
```

---

## Database Schema

### Tables

All tables use the `at_` prefix (short for arbeitszeitcheck):

- `oc_at_entries` - Time entries
- `oc_at_absences` - Absence requests
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

3. **Document in API docs:**
   - Update `docs/API-Documentation.en.md`
   - Include request/response examples

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

### Using Nextcloud Vue Components

Always use components from `@nextcloud/vue`:

```vue
<template>
  <NcAppContent>
    <NcButton type="primary" @click="save">
      Save
    </NcButton>
  </NcAppContent>
</template>

<script>
import { NcAppContent, NcButton } from '@nextcloud/vue'

export default {
  components: {
    NcAppContent,
    NcButton
  }
}
</script>
```

### CSS Isolation

**Always use scoped styles:**

```vue
<style scoped>
.timetracking-component {
  /* Styles here */
}
</style>
```

**Use BEM naming:**
```css
.timetracking-block {}
.timetracking-block__element {}
.timetracking-block__element--modifier {}
```

**Use CSS variables:**
```css
.timetracking-button {
  background: var(--color-primary);
  color: var(--color-main-text);
}
```

### Internationalization

Use Nextcloud's translation system:

```vue
<template>
  <p>{{ $t('arbeitszeitcheck', 'Hello world') }}</p>
</template>
```

Add translations to `l10n/de.json` and `l10n/en.json`.

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

```javascript
import { mount } from '@vue/test-utils'
import Component from '../Component.vue'

describe('Component', () => {
  it('renders correctly', () => {
    const wrapper = mount(Component)
    expect(wrapper.exists()).toBe(true)
  })
})
```

Run tests:
```bash
npm test
```

### Accessibility Tests

```javascript
import { axe, toHaveNoViolations } from 'jest-axe'

expect.extend(toHaveNoViolations)

test('component is accessible', async () => {
  const { container } = render(Component)
  const results = await axe(container)
  expect(results).toHaveNoViolations()
})
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

- **ESLint** with strict configuration
- **Vue 3 Composition API** preferred
- **No console.log** in production code
- **Proper error handling**

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
- **Vue.js 3 Documentation:** https://vuejs.org/
- **PHPUnit Documentation:** https://phpunit.de/
- **Jest Documentation:** https://jestjs.io/

---

**Last Updated:** 2025-12-29
