# Architecture

HWOS is implemented as separately versioned external Dolibarr modules. `hwoscore` is the required foundation for shared permissions, migrations, audit events, idempotency support, and common server-side services.

Standard Dolibarr objects remain authoritative. Feature modules may extend them through permissions, hooks, triggers, tabs, Extrafields, PDFs, and narrow domain services. A feature module may add custom tables only when Dolibarr has no suitable native object.

Promotion path:

`Git → DEV → immutable release artifact → TEST → explicit approval → production`
