# Acceptance test report

Generated: 2026-11-08T17:33:26+00:00 by `php tests/acceptance.php` against `http://127.0.0.1:8080`.

| # | Scenario | Result | Checks |
|---|---|---|---|
| 1 | A new customer can register and sign in | PASS | 11/11 |
| 2 | Invalid email rejected before the password step | PASS | 3/3 |
| 3 | Incorrect credentials do not reveal registered emails | PASS | 3/3 |
| 4 | Signed-in customer can register multiple pets | PASS | 4/4 |
| 5 | Customer cannot view or edit another customer's pets | PASS | 7/7 |
| 6 | Customer can submit a grooming appointment request | PASS | 10/10 |
| 7 | Request appears in the Grooming receptionist's appointment management | PASS | 9/9 |
| 8 | Receptionist cannot access another business unit's records | PASS | 4/4 |
| 9 | Customer can browse Grooming Services products | PASS | 9/9 |
| 10 | Customer can add, update and remove cart items | PASS | 7/7 |
| 11 | Cart A and Cart B remain separate | PASS | 4/4 |
| 12 | Cart persists after navigating away, signing out and in | PASS | 2/2 |
| 13 | Customer can select pickup or delivery at checkout | PASS | 8/8 |
| 14 | Checkout recalculates prices and totals on the server | PASS | 1/1 |
| 15 | Customer cannot order another business unit's products | PASS | 2/2 |
| 16 | Cart contents do not reserve or deduct inventory | PASS | 3/3 |
| 17 | Database failures: no false success, no partial order | PASS | 3/3 |

## Failed checks

None.
