---
name: db-change
description: Plan and write a schema change (new table, column, index or constraint) or transactional write code (several rows or tables, money, stock status) for the Laravel backend, with a rollback plan. Use for any new migration, and for any Service method that writes more than one row.
---

# Database change

Rules: [database-acid](../../rules/database-acid.md), [multi-tenancy-and-roles](../../rules/multi-tenancy-and-roles.md), [testing](../../rules/testing.md).

## 1. Discover
1. List the tables involved: `ls backend/database/migrations | grep <table>`. Read every migration for those tables, in date order. The current schema is the sum of all of them.
2. Read the models and the Services that write to those tables.
3. Decide: is the data per shop (`shop_id` + `BelongsToShop`) or platform-wide? If unclear, **stop and ask the user**.

## 2. Design
Write down before coding:
- columns with types (money is `decimal(14, 2)`), nullability and defaults
- foreign keys and their delete behavior
- unique indexes (with `shop_id` when per shop)
- indexes for filter and sort columns
- for writes: which rows are locked, in what order, which rules are checked under the lock, and which values are recomputed

## 3. Write the migration
1. `cd backend && php artisan make:migration <verb>_<what>_table`
2. Write `up()` and an exact reverse `down()`.
3. Check deploy safety against database-acid: nullable or default for new columns, no drop or rename of a column still in use, works on MySQL and SQLite.

## 4. Rollback plan (goes in the PR body)
- **Schema only:** "`php artisan migrate:rollback --step=1` reverses it." (A human runs this. The agent's settings ask first.)
- **Changes or deletes data:** describe how to restore it (a backup before deploy, or a reverse data migration), because `down()` can't bring back lost rows.

## 5. Write the transactional code
Follow database-acid: `DB::transaction`, `lockForUpdate` re-reads, `BusinessRuleException`, `Money`, a state check that stops repeats, and side effects after commit.

## 6. Test
Run `/write-tests`. It MUST include: success writes every row; a refused rule returns 409 and leaves `assertDatabaseCount` unchanged; exact money totals after several operations.

## 7. Verify
Run `/verify` (its full test step also runs every migration).

## Output checklist
- [ ] Migration file name(s)
- [ ] Constraints and indexes added
- [ ] Locking order and rules checked under the lock
- [ ] Deploy-safety check passed
- [ ] Rollback plan text for the PR
- [ ] Tests added
- [ ] `/verify` table
