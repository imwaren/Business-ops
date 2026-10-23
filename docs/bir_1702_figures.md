# BIR 1702 figure mapping

How `Bir_return_generator` fills 1702-RT / 1702Q from the P&L sheet and master `branch`. Amounts are written as **whole pesos** (BIR: 49 centavos drop, 50 or more round up).

## Period

| Return | `date_from` | `date_to` |
| --- | --- | --- |
| **1702-RT** (`type=1702_rt`) | `{year}-01-01` | `{year}-12-31` |
| **1702Q** (`type=1702q`) | First day of the quarter | Last day of the quarter |

Quarters: Q1 Jan–Mar, Q2 Apr–Jun, Q3 Jul–Sep, Q4 Oct–Dec. 1702Q is queued only for closed months **3, 6, 9, 12**. 1702-RT is queued only for closed month **12** (annual return).

Example — December 2026 close:

- **1702-RT** figures = P&L **2026-01-01** through **2026-12-31** (all 12 months, not December only)
- **1702Q** Q4 figures = P&L **2026-10-01** through **2026-12-31** (Oct + Nov + Dec)

Default `branch_id` is **2** (Oakridge). Header TIN, registered name, and address come from master `branch`.

## Source

`Reports::build_profit_and_loss_for_bir($date_from, $date_to, $branch_id)` — the same P&L OAK sheet as `GET /reports/get_profit_and_loss` (`tab=per_account_type`) for that branch and date range.

Mapped in `Bir_return_generator::_pnl_totals()`.

## Form lines

| Form item | Field | How it is computed |
| --- | --- | --- |
| Header 1 | Calendar | Always marked `X` |
| Header 2 | Year ended MM / YY | 1702-RT: `12` and last two digits of `year`. 1702Q: quarter-end month and last two digits of `year` |
| Header 3 / 4 | Amended / Short period | Always **No** |
| Header 6 | TIN | First 9 digits of `branch.tin` (last 5 `00000` are printed on the template) |
| Header 8 / 9 | Name / address | `branch.name`, `branch.address` |
| Header 13 | Method of deductions | Itemized marked `X` |
| **29** | Net sales / revenues / fees | P&L `net_revenues` |
| **30** | Cost of sales / services | P&L `cost_of_sales.total` (same as `net_revenues - gross_margin`) |
| **31** | Gross income from operation | P&L `gross_margin` |
| **34** | Ordinary itemized deductions | `branch_operating_costs.total` + `general_and_admin_expenses.total` + `nopex.amount` |
| **39** | Net taxable income / (loss) | P&L `net_income` |
| **40** | Applicable income tax rate | `25` (RCIT) |
| **41 / 43 / 14** | Tax due | If `net_income` > 0 then `round(net_income * 0.25)` else `0`. MCIT is not computed separately; 43 uses the same RCIT amount. |
| **15 / 55** | Tax credits / payments | `0` (no 2307 / prior-year credits wired yet) |
| **16 / 56** | Net tax payable | Item 14 − Item 15 |
| **21** | Total amount payable | Item 16 (penalties 17–20 are `0`) |

1702Q Part II uses the same tax-due / credits / payable amounts on its equivalent lines (14, credits, net payable, total payable).

## Overlay QA

Overlay text is **red Helvetica** so placement can be checked against the printed boxes. Coordinates live in `app/Libraries/Bir_1702.php` (`_map_1702_rt()`, `_map_1702q()`). Switch `SetTextColor(220, 0, 0)` back to black when alignment is signed off.
