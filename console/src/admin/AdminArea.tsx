/**
 * The admin panel's frame (`/admin/*`): a team invite link opens without signing in (StaffInviteAccept); signed-out
 * visitors get the sign-in form; signed in, the nav shows the pages the role may use (the session's abilities, `may`
 * for the pages too), the first of them is where it opens, then a sign-out button and the page. Who may do what is the
 * backend's to say: an admin call it refuses (403) shows the backend's message instead of the page.
 *
 * A customer's invoice opens at `/admin/invoices/:id` for staff who may read customers.
 *
 * Not here: the pages (DashboardPage.tsx, CostsPage.tsx, VoicePlatformPage.tsx, PlatformBillingPage.tsx, StaffPage.tsx, AuditPage.tsx, Customers.tsx, CustomerPage.tsx, Pricing.tsx, Catalog.tsx, SignupsPage.tsx, EmailPage.tsx,
 * SmsPage.tsx, SsoPage.tsx, CaptchaPage.tsx, PaymentsPage.tsx, InvoiceSettingsPage.tsx, CustomerInvoicePage.tsx, WebsitePage.tsx, StarterAgentsPage.tsx; the rest of admin comes with K1's later slices).
 */
import { useEffect, useState, type ReactElement } from "react";
import { NavLink, Navigate, Route, Routes, useNavigate } from "react-router-dom";
import { kitApi, type User } from "../api";
import { SignIn } from "../setup/SignIn";
import { t, type TextKey } from "../text";
import { AuditPage } from "./AuditPage";
import { CaptchaPage } from "./CaptchaPage";
import { Catalog } from "./Catalog";
import { CostsPage } from "./CostsPage";
import { CustomerInvoicePage } from "./CustomerInvoicePage";
import { CustomerPage } from "./CustomerPage";
import { Customers } from "./Customers";
import { DashboardPage } from "./DashboardPage";
import { EmailPage } from "./EmailPage";
import { InvoiceSettingsPage } from "./InvoiceSettingsPage";
import { MayContext, mayFor } from "./may";
import { PaymentsPage } from "./PaymentsPage";
import { PlatformBillingPage } from "./PlatformBillingPage";
import { Pricing } from "./Pricing";
import { SignupsPage } from "./SignupsPage";
import { SmsPage } from "./SmsPage";
import { StaffInviteAccept } from "./StaffInviteAccept";
import { StaffPage } from "./StaffPage";
import { SsoPage } from "./SsoPage";
import { StarterAgentsPage } from "./StarterAgentsPage";
import { VoicePlatformPage } from "./VoicePlatformPage";
import { WebsitePage } from "./WebsitePage";

/** Each page of the admin panel, the ability it takes, and its words; the first a role may use is where it opens. */
const PAGES: { path: string; ability: string; title: TextKey; element: ReactElement }[] = [
  { path: "dashboard", ability: "reports", title: "dashboardTitle", element: <DashboardPage /> },
  { path: "customers", ability: "customers_read", title: "customersTitle", element: <Customers /> },
  { path: "telenow", ability: "health", title: "platformTitle", element: <VoicePlatformPage /> },
  { path: "costs", ability: "reports", title: "costsTitle", element: <CostsPage /> },
  { path: "pricing", ability: "money", title: "pricingTitle", element: <Pricing /> },
  { path: "payments", ability: "money", title: "paymentsTitle", element: <PaymentsPage /> },
  { path: "invoices", ability: "money", title: "invoiceSettingsTitle", element: <InvoiceSettingsPage /> },
  { path: "platform-billing", ability: "money", title: "platformBillingTitle", element: <PlatformBillingPage /> },
  { path: "catalog", ability: "settings", title: "catalogTitle", element: <Catalog /> },
  { path: "starters", ability: "starters", title: "startersTitle", element: <StarterAgentsPage /> },
  { path: "signups", ability: "customers_manage", title: "signupsTitle", element: <SignupsPage /> },
  { path: "email", ability: "settings", title: "emailTitle", element: <EmailPage /> },
  { path: "sms", ability: "settings", title: "smsTitle", element: <SmsPage /> },
  { path: "sso", ability: "settings", title: "ssoTitle", element: <SsoPage /> },
  { path: "captcha", ability: "settings", title: "captchaTitle", element: <CaptchaPage /> },
  { path: "website", ability: "settings", title: "websiteTitle", element: <WebsitePage /> },
  { path: "staff", ability: "staff", title: "staffTitle", element: <StaffPage /> },
  { path: "audit", ability: "audit", title: "auditTitle", element: <AuditPage /> },
];

export function AdminArea() {
  const [user, setUser] = useState<User | null | undefined>(undefined);
  const navigate = useNavigate();

  useEffect(() => {
    kitApi.session().then((s) => setUser(s.user), () => setUser(null));
  }, []);

  return (
    <Routes>
      <Route path="invite/:token" element={<StaffInviteAccept onJoined={(u) => { setUser(u); navigate("/admin", { replace: true }); }} />} />
      <Route path="*" element={<Signed user={user} setUser={setUser} />} />
    </Routes>
  );
}

function Signed({ user, setUser }: { user: User | null | undefined; setUser: (u: User | null) => void }) {
  if (user === undefined) return <p aria-busy="true">{t("loading")}</p>;
  if (user === null) return <SignIn help={t("adminSignInHelp")} offer={["reset"]} onSignedIn={setUser} />;
  const may = mayFor(user);
  const pages = PAGES.filter((p) => may(p.ability));

  return (
    <MayContext.Provider value={may}>
      <div className="admin">
        <nav className="admin-nav" aria-label={t("adminNav")}>
          {pages.map((p) => <NavLink key={p.path} to={`/admin/${p.path}`}>{t(p.title)}</NavLink>)}
          <button
            type="button"
            className="secondary"
            onClick={async () => {
              await kitApi.signOut();
              setUser(null);
            }}
          >
            {t("signOut")}
          </button>
        </nav>
        <Routes>
          {pages.map((p) => <Route key={p.path} path={p.path} element={p.element} />)}
          {may("customers_read") && <Route path="customers/:id" element={<CustomerPage />} />}
          {may("customers_read") && <Route path="invoices/:id" element={<CustomerInvoicePage />} />}
          <Route path="*" element={pages.length > 0 ? <Navigate to={`/admin/${pages[0].path}`} replace /> : <p className="muted">{t("staffNothing")}</p>} />
        </Routes>
      </div>
    </MayContext.Provider>
  );
}
