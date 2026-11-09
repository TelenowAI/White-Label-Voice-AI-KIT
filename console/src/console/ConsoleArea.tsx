/**
 * The customer console's frame (`/console/*`): staff looking at it as a customer see a read-only banner with a way back
 * (ViewingAsBanner); an invite link opens without signing in (InviteAccept); everything else
 * needs a signed-in member. Signed in: the account switcher (when the user belongs to several), the nav, the
 * "service paused" notice whenever the backend says so, and the page — Home (with the account's call health:
 * calls/CallHealthPanel.tsx), Agents (and one agent), Calls (and one
 * call), Insights, Phone, Follow-ups, Campaigns (and one campaign), Knowledge (and one base, and a table in it), Voices, Numbers, Call routing,
 * Wallet (and from it the invoices, and one invoice at `/console/invoices/:id`, the path the payment-received email links to), Do not call,
 * Consent, Caller memory or Team.
 *
 * Not here: the pages themselves (agents/, calls/, insights/, phone/, campaigns/, knowledge/, voices/, numbers/, routing/, wallet/,
 * invoices/, dnc/, consent/, memory/, team/, account/).
 */
import { Suspense, lazy, useCallback, useEffect, useMemo, useState } from "react";
import { NavLink, Navigate, Route, Routes, useNavigate } from "react-router-dom";
import { KitApiError, kitApi, type ConsoleHome, type User, type ViewingAs, type WorkspaceList } from "../api";
import { AgentPage } from "../agents/AgentPage";
import { Agents } from "../agents/Agents";
import { CallPage } from "../calls/CallPage";
import { FollowUpsPage } from "../calls/FollowUpsPage";
import { CampaignPage } from "../campaigns/CampaignPage";
import { CampaignsPage } from "../campaigns/CampaignsPage";
import { ConsentPage } from "../consent/ConsentPage";
import { DncPage } from "../dnc/DncPage";
import { InsightsPage } from "../insights/InsightsPage";
import { InvoicePage } from "../invoices/InvoicePage";
import { InvoicesPage } from "../invoices/InvoicesPage";
import { KnowledgeBasePage } from "../knowledge/KnowledgeBasePage";
import { KnowledgePage } from "../knowledge/KnowledgePage";
import { KnowledgeTablePage } from "../knowledge/KnowledgeTablePage";
import { MemoryPage } from "../memory/MemoryPage";
import { NumbersPage } from "../numbers/NumbersPage";
import { PhonePage } from "../phone/PhonePage";
import { CallRoutingPage } from "../routing/CallRoutingPage";
import { VoicesPage } from "../voices/VoicesPage";
import { CallHealthPanel } from "../calls/CallHealthPanel";
import { Calls } from "../calls/Calls";
import { SignIn } from "../setup/SignIn";
import { AccountPage } from "../account/AccountPage";
import { TeamPanel, type TeamCalls } from "../team/TeamPanel";
import { WalletPage } from "../wallet/WalletPage";
import { t } from "../text";
import { InviteAccept } from "./InviteAccept";

/** Loaded only when opened: it brings the drawing library with it. */
const AgentFlowPage = lazy(() => import("../agents/flow/FlowPage").then((page) => ({ default: page.FlowPage })));

export function ConsoleArea() {
  const [user, setUser] = useState<User | null | undefined>(undefined);
  const [viewingAs, setViewingAs] = useState<ViewingAs | null>(null);
  const recheck = useCallback(() => {
    kitApi.session().then((s) => {
      setUser(s.user);
      setViewingAs(s.viewingAs ?? null);
    }, () => setUser(null));
  }, []);

  useEffect(recheck, [recheck]);

  return (
    <Routes>
      <Route path="invite/:token" element={<InviteAccept onJoined={recheck} />} />
      <Route
        path="*"
        element={
          user === undefined ? (
            <p aria-busy="true">{t("loading")}</p>
          ) : user === null ? (
            <SignIn help={t("consoleSignInHelp")} onSignedIn={setUser} />
          ) : (
            <>
              {viewingAs && <ViewingAsBanner viewingAs={viewingAs} />}
              <Account key={`${user.id}-${viewingAs?.customerId ?? ""}`} viewing={viewingAs !== null} onSignedOut={() => setUser(null)} />
            </>
          )
        }
      />
    </Routes>
  );
}

/** Staff looking at this console as a customer (read-only): who, until when, and a way back to the admin panel. */
function ViewingAsBanner({ viewingAs }: { viewingAs: ViewingAs }) {
  const navigate = useNavigate();
  return (
    <div role="status" className="viewing-as">
      <span>{t("viewAsBanner", { name: viewingAs.name, until: new Date(viewingAs.until).toLocaleTimeString() })}</span>{" "}
      <button type="button" onClick={async () => {
        await kitApi.stopViewingAs();
        navigate(`/admin/customers/${encodeURIComponent(viewingAs.customerId)}`);
      }}>
        {t("viewAsStop")}
      </button>
    </div>
  );
}

function Account({ viewing, onSignedOut }: { viewing: boolean; onSignedOut: () => void }) {
  const [home, setHome] = useState<ConsoleHome | null>(null);
  const [list, setList] = useState<WorkspaceList | null>(null);
  const [failure, setFailure] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const [h, l] = await Promise.all([kitApi.consoleHome(), kitApi.myWorkspaces()]);
      setHome(h);
      setList(l);
      setFailure(null);
    } catch (err) {
      setFailure(err instanceof KitApiError && err.status === 403 ? t("noAccount") : err instanceof Error ? err.message : String(err));
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const calls = useMemo<TeamCalls>(
    () => ({
      load: () => kitApi.team(),
      invite: (email, role) => kitApi.inviteTeamMember(email, role),
      revoke: (inviteId) => kitApi.revokeTeamInvite(inviteId),
      remove: (userId) => kitApi.removeTeamMember(userId),
    }),
    // The calls name no account (the backend uses the current one); a new account still means a new team to load.
    [home?.workspace.id],
  );

  const signOut = async () => {
    await kitApi.signOut();
    onSignedOut();
  };

  return (
    <div className="console">
      <nav className="admin-nav" aria-label={t("consoleTitle")}>
        <NavLink to="/console" end>{t("homeNav")}</NavLink>
        <NavLink to="/console/agents">{t("agentsTitle")}</NavLink>
        <NavLink to="/console/calls">{t("callsTitle")}</NavLink>
        <NavLink to="/console/insights">{t("insightsTitle")}</NavLink>
        <NavLink to="/console/phone">{t("phoneTitle")}</NavLink>
        <NavLink to="/console/followups">{t("followUpsTitle")}</NavLink>
        <NavLink to="/console/campaigns">{t("campaignsTitle")}</NavLink>
        <NavLink to="/console/knowledge">{t("knowledgeTitle")}</NavLink>
        <NavLink to="/console/voices">{t("voicesTitle")}</NavLink>
        <NavLink to="/console/numbers">{t("numbersTitle")}</NavLink>
        <NavLink to="/console/routing">{t("routingTitle")}</NavLink>
        <NavLink to="/console/wallet">{t("walletTitle")}</NavLink>
        <NavLink to="/console/dnc">{t("dncTitle")}</NavLink>
        <NavLink to="/console/consent">{t("consentTitle")}</NavLink>
        <NavLink to="/console/memory">{t("memoryTitle")}</NavLink>
        <NavLink to="/console/team">{t("teamTitle")}</NavLink>
        <NavLink to="/console/account">{t("accountTitle")}</NavLink>
        {list && list.workspaces.length > 1 && (
          <label className="switcher">
            <span>{t("accountSwitch")}</span>
            <select
              value={list.workspaces.find((w) => w.current)?.id}
              onChange={async (e) => {
                setList(await kitApi.selectWorkspace(e.target.value));
                await load();
              }}
            >
              {list.workspaces.map((w) => (
                <option key={w.id} value={w.id}>
                  {w.name}
                </option>
              ))}
            </select>
          </label>
        )}
        <button type="button" className="secondary" onClick={signOut}>
          {t("signOut")}
        </button>
      </nav>
      {failure && <p role="alert" className="error">{failure}</p>}
      {home?.paused && (
        <p role="status" className={`paused paused-${home.paused.reason}`}>
          {home.paused.message}
        </p>
      )}
      {home && (
        <Routes>
          <Route
            index
            element={
              <section aria-labelledby="home-title">
                <h1 id="home-title">{t("welcome", { name: home.workspace.name })}</h1>
                <p className="muted">{viewing ? t("viewAsRole") : t("yourRole", { role: home.role })}</p>
                <CallHealthPanel />
              </section>
            }
          />
          <Route path="agents" element={<Agents />} />
          <Route path="agents/:id" element={<AgentPage />} />
          <Route path="agents/:id/flow" element={<Suspense fallback={<p className="muted">{t("loading")}</p>}><AgentFlowPage /></Suspense>} />
          <Route path="calls" element={<Calls />} />
          <Route path="calls/:id" element={<CallPage />} />
          <Route path="insights" element={<InsightsPage />} />
          <Route path="phone" element={<PhonePage />} />
          <Route path="followups" element={<FollowUpsPage />} />
          <Route path="campaigns" element={<CampaignsPage />} />
          <Route path="campaigns/:id" element={<CampaignPage />} />
          <Route path="knowledge" element={<KnowledgePage />} />
          <Route path="knowledge/:id" element={<KnowledgeBasePage />} />
          <Route path="knowledge/:baseId/documents/:documentId/table" element={<KnowledgeTablePage />} />
          <Route path="voices" element={<VoicesPage />} />
          <Route path="numbers" element={<NumbersPage />} />
          <Route path="routing" element={<CallRoutingPage />} />
          <Route path="wallet" element={<WalletPage />} />
          <Route path="invoices" element={<InvoicesPage />} />
          <Route path="invoices/:id" element={<InvoicePage />} />
          <Route path="dnc" element={<DncPage />} />
          <Route path="consent" element={<ConsentPage />} />
          <Route path="memory" element={<MemoryPage />} />
          <Route path="team" element={<TeamPanel calls={calls} />} />
          <Route path="account" element={<AccountPage />} />
          <Route path="*" element={<Navigate to="/console" replace />} />
        </Routes>
      )}
    </div>
  );
}
