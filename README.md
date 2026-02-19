## NIG Ikimina Frontend (Static Prototype)

This folder contains a static HTML prototype for the NIG Ikimina management system, focused on clean, cooperative-style UI and Kinyarwanda-only content.

### Pages

- **index.html**: Home/landing page explaining the system and linking to Login / Signup.
- **login.html**: Login form (nimero ya telefoni/email + ijambo ry’ibanga).
- **signup.html**: Signup form with role selection (utari umunyamuryango / umunyamuryango).
- **non-member-dashboard.html**: Dashboard for non-members (loan summary, apply for loan, status, history).
- **member-dashboard.html**: Dashboard for members (shares, loans, interests, monthly shares and payment history, request share withdrawal).
- **admin-dashboard.html**: Admin workspace with sidebar navigation (Dashboard, Abanyamuryango, Inguzanyo, Imigabane, Kwishyura, Expenses, Assets, Raporo, Settings).

### Design System

- **Primary green** `#2F6B4F`, **dark green** `#1F4D3A`, **warm orange** `#E89C2C`, **earth brown** `#6B4A2D`
- Background **off white** `#F8FAF9`, borders **soft gray** `#E5E7EB`
- Components: cards, tables, primary (green) & secondary (orange) buttons.

### Tech

- Pure static HTML + [Tailwind via CDN](`https://cdn.tailwindcss.com`) for layout and typography.
- Shared custom utilities in `styles.css` (cards, buttons, badges, tables, sidebar links).

