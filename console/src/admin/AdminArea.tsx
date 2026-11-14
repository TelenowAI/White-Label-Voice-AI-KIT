/**
 * The admin panel's frame (`/admin/*`): a team invite link opens without signing in (StaffInviteAccept); signed-out
 * visitors get the sign-in form; signed in, the shared frame (shell/AppShell.tsx) shows in its side menu the pages the
 * role may use, in groups (the session's abilities, `may` for the pages too), the first of them is where it opens, then
 * Sign out below the menu, beside the page. Who may do what is the
 * backend's to say: an admin call it refuses (403) shows the backend's message instead of the page.
 *
 * A customer's invoice opens at `/admin/invoices/:id` for staff who may read customers.
 *
 * Not here: the pages (DashboardPage.tsx, CostsPage.tsx, VoicePlatformPage.tsx, PlatformBillingPage.tsx, StaffPage.tsx, AuditPage.tsx, Customers.tsx, CustomerPage.tsx, Pricing.tsx, Catalog.tsx, SignupsPage.tsx, EmailPage.tsx,
 * SmsPage.tsx, SsoPage.tsx, CaptchaPage.tsx, PaymentsPage.tsx, InvoiceSettingsPage.tsx, CustomerInvoicePage.tsx, WebsitePage.tsx, StarterAgentsPage.tsx; the rest of admin comes with K1's later slices).
 */
import { useEffect, useState, type ReactElement } from "react";
import { Navigate, Route, Routes, useNavigate } from "react-router-dom";
import { kitApi, type User } from "../api";
import { SignIn } from "../setup/SignIn";
import { AppShell, type NavGroup } from "../shell/AppShell";
import type { NavIcon } from "../shell/navIcons";
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

type Page = { path: string; ability: string; title: TextKey; group: TextKey | null; icon: NavIcon; element: ReactElement };

/**
 * Each page of the admin panel, the ability it takes, its words, its side-menu group and icon; the first a role may use
 * is where it opens. A group is a run of pages next to each other here, so the menu keeps this order.
 */
const PAGES: Page[] = [
  { path: "dashboard", ability: "reports", title: "dashboardTitle", group: null, icon: "dashboard", element: <DashboardPage /> },
  { path: "customers", ability: "customers_read", title: "customersTitle", group: null, icon: "team", element: <Customers /> },
  { path: "telenow", ability: "health", title: "platformTitle", group: null, icon: "platform", element: <VoicePlatformPage /> },
  { path: "costs", ability: "reports", title: "costsTitle", group: "adminGroupMoney", icon: "costs", element: <CostsPage /> },
  { path: "pricing", ability: "money", title: "pricingTitle", group: "adminGroupMoney", icon: "pricing", element: <Pricing /> },
  { path: "payments", ability: "money", title: "paymentsTitle", group: "adminGroupMoney", icon: "payments", element: <PaymentsPage /> },
  { path: "invoices", ability: "money", title: "invoiceSettingsTitle", group: "adminGroupMoney", icon: "invoices", element: <InvoiceSettingsPage /> },
  { path: "platform-billing", ability: "money", title: "platformBillingTitle", group: "adminGroupMoney", icon: "bank", element: <PlatformBillingPage /> },
  { path: "catalog", ability: "settings", title: "catalogTitle", group: "adminGroupOffer", icon: "catalog", element: <Catalog /> },
  { path: "starters", ability: "starters", title: "startersTitle", group: "adminGroupOffer", icon: "starters", element: <StarterAgentsPage /> },
  { path: "signups", ability: "customers_manage", title: "signupsTitle", group: "adminGroupMessages", icon: "signups", element: <SignupsPage /> },
  { path: "email", ability: "settings", title: "emailTitle", group: "adminGroupMessages", icon: "email", element: <EmailPage /> },
  { path: "sms", ability: "settings", title: "smsTitle", group: "adminGroupMessages", icon: "sms", element: <SmsPage /> },
  { path: "sso", ability: "settings", title: "ssoTitle", group: "adminGroupMessages", icon: "sso", element: <SsoPage /> },
  { path: "captcha", ability: "settings", title: "captchaTitle", group: "adminGroupMessages", icon: "captcha", element: <CaptchaPage /> },
  { path: "website", ability: "settings", title: "websiteTitle", group: "adminGroupSite", icon: "website", element: <WebsitePage /> },
  { path: "staff", ability: "staff", title: "staffTitle", group: "adminGroupSite", icon: "staff", element: <StaffPage /> },
  { path: "audit", ability: "audit", title: "auditTitle", group: "adminGroupSite", icon: "audit", element: <AuditPage /> },
];

/** The side menu for the pages a role may use: each run of pages of one group under its title, in PAGES' order. */
function navGroups(pages: Page[]): NavGroup[] {
  const groups: NavGroup[] = [];
  for (const p of pages) {
    const link = { to: `/admin/${p.path}`, label: p.title, icon: p.icon };
    const last = groups[groups.length - 1];
    if (last && (last.title ?? null) === p.group) last.links.push(link);
    else groups.push({ title: p.group ?? undefined, links: [link] });
  }
  return groups;
}

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
        <AppShell
          navLabel={t("adminNav")}
          groups={navGroups(pages)}
          bottom={
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
          }
        >
          <Routes>
            {pages.map((p) => <Route key={p.path} path={p.path} element={p.element} />)}
            {may("customers_read") && <Route path="customers/:id" element={<CustomerPage />} />}
            {may("customers_read") && <Route path="invoices/:id" element={<CustomerInvoicePage />} />}
            <Route path="*" element={pages.length > 0 ? <Navigate to={`/admin/${pages[0].path}`} replace /> : <p className="muted">{t("staffNothing")}</p>} />
          </Routes>
        </AppShell>
      </div>
    </MayContext.Provider>
  );
}
