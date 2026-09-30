# Multi-Outlet

The POS supports multiple branches (outlets) from a single shared database. A sales outlet can use its own warehouse or a warehouse shared through `outlet_warehouse_access`. The shift records both its outlet and stock warehouse. For staging/production rollout of an existing single-outlet install see the **Rollout** section below.

## Model

- `outlets` is the business boundary above `warehouses`.
- `warehouses.outlet_id` identifies the owning outlet; `outlet_warehouse_access` grants sales outlets access to shared stock warehouses.
- `PUSAT` is the central warehouse/outlet for stock distribution and is **not sales-enabled**.
- Dimsum Weigu uses `PUSAT` as its central stock warehouse plus one active stock warehouse per sales outlet.
- Warehouse transfers reduce PUSAT stock when sent and add the received quantity to the destination outlet. Checkout consumes that outlet's stock.
- Cashier shift closing records counted menu stock and returns the counted remainder from the outlet warehouse to PUSAT.
- Cashier assignments are stored in `user_outlets` (one default per user).
- Active outlet context is session-based; the active shift's `outlet_id` identifies the branch while `warehouse_id` identifies shared stock. Outlet switching is locked while a shift is open.
- Global fallback remains for legacy single-outlet installations.

## Scope Policy

- Customers, loyalty, suppliers, receivables, and payables remain global.
- Transactions and shifts store outlet context separately from warehouse stock. Product inventory follows each warehouse balance.
- `PUSAT` is the central warehouse and is not sales-enabled.
- A cashier uses one active shift, one sales outlet, and that outlet's stock warehouse. Warehouse transfers, checkout, and shift-close returns lock inventory rows and run atomically.
- Global configuration remains a fallback for legacy single-outlet installations.

## Day-to-Day Operations

After the first install the active outlet is set in the navbar selector (`OutletSwitcher`). While a shift is open the selector is locked to the shift's outlet.

- **Opening a shift** ? select the assigned outlet stock warehouse.
- **Switching outlets** — close all open shifts first; the selector allows switching between assigned active outlets.
- **Stock transfers** ? warehouse staff draft, send, and receive transfers from `PUSAT` to outlet warehouses. The send step decreases PUSAT immediately; the receive step records stock at the outlet.
- **Shift closing** ? cashier enters the counted remaining menu stock. The system returns that quantity to PUSAT and clears the outlet balance.
- **Per-outlet settings** — store profile, printer, payment settings, bank accounts, pricing, vouchers, dine-in, WhatsApp, and sales target each have an outlet override and a global fallback.
- **Reports** ? sales and dashboards group by transaction outlet; inventory and stock movements group by warehouse.
- **Receivables and payables** — remain in a global ledger; visibility and payment authorization derive from the source transaction/purchase-order outlet.

## Rollout (existing single-outlet installs)

> Read-only until the business approves every data mapping. Do not run `--fix` or `--strict` until staging has been exercised end-to-end.

### Preflight

1. Back up the database and restore that backup into staging.
2. Run `php artisan migrate --pretend`, then `php artisan migrate` on staging.
3. Run `php artisan outlet:audit` and `php artisan outlet:legacy-audit`.
4. Run `php artisan inventory:reconcile` and save the output.
5. Do not run `inventory:reconcile --fix` until the team confirms that warehouse pivot stock is authoritative.
6. Classify every warehouse-less transaction, shift, stock mutation, purchase, receiving, return, and opname.
7. Assign every active user to the correct outlet and verify one default outlet.

## Outlet Setup

Create or verify:

| Code | Role | Sales |
| --- | --- | --- |
| `PUSAT` | Central warehouse | No |
| `MAL` | Malabar outlet | Yes |
| `TKB` | Taman Kencana outlet | Yes |
| `PUT` | Puter outlet | Yes |

For every sales outlet:

- Link one active branch warehouse.
- Attach product stock rows and load opening stock through approved transfers or opname.
- Assign cashiers and managers through user outlet assignments.
- Configure store profile, printer, payment settings, bank accounts, pricing, vouchers, dine-in tables, WhatsApp, and sales target as required.

## Pilot Checklist

Run the following with a test user assigned to one outlet:

- Switch only between assigned active outlets.
- Confirm outlet switching is blocked while a shift is open.
- Confirm the non-sales `PUSAT` outlet itself cannot be selected as a sales outlet and each cashier opens a shift against their assigned outlet warehouse.
- Transfer stock from `PUSAT`, sell from the outlet balance, then close the shift and confirm the counted remainder returns to `PUSAT`.
- Open and close a shift with cash movement.
- Complete cash, bank transfer, QRIS, and split-payment sales.
- Confirm transaction, stock, receipt, and payment webhook outlet context.
- Test offline sync and confirm it cannot post without the correct active shift.
- Test return, stock opname, transfer, purchase order, goods receiving, and supplier return.
- Test dine-in QR ordering at the correct outlet table.
- Verify sales, profit, insights, receivable, payable, and dashboard totals for the active outlet.
- Verify an Outlet A user cannot read or mutate Outlet B records.
- Run `php artisan outlet:audit --strict` and require success.

## Production Gate

Do not enable the second sales outlet until all of the following are true:

- `php artisan outlet:audit --strict` exits successfully.
- `php artisan outlet:legacy-audit` output has been reviewed and mapped where appropriate.
- Stock reconciliation output is clean or formally accepted.
- Staging pilot passes and the rollback plan is documented.
- Backups and queue/scheduler/WhatsApp monitoring are confirmed.

The final major release should be `v3.0.0` only after this gate and the first production pilot are accepted.
