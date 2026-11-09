/**
 * Console → Call routing (`/console/routing`; contract/kit-api.yaml getRouting): ordered rules for who takes a call to one of the account's routed
 * numbers. Says in plain words how they work — tried in order, the first that matches takes the call, and the number's own agent answers when
 * none does or when this server cannot answer within about a second — then places the account's numbers and which are routed
 * (RoutedNumbers.tsx), whether the voice platform asks this server once a number was first routed (RoutingConnectionPanel.tsx), the rules
 * (RoutingRuleList.tsx) and trying a pretend call (TryRoutingCall.tsx). Every member sees it; only the account's managers (`canManage`) change
 * it or run the platform's test, and everyone else is told so. The account's saved agents (every page) are read for the managers' rule form.
 *
 * Not here: which rule takes a real call (the backend, asked by the voice platform). Used by: console/ConsoleArea.tsx.
 *
 * This folder (routing/): CallRoutingPage.tsx (this page), RoutedNumbers.tsx, RoutingConnectionPanel.tsx, RoutingRuleList.tsx,
 * RoutingRuleForm.tsx, TryRoutingCall.tsx, ruleFormValues.ts (the form's values, what is sent, refusals placed), ruleSummary.ts (a rule in
 * words), routingWords.ts (states, problems and days in words).
 */
import { useCallback, useEffect, useState } from "react";
import { kitApi, type Agent, type PhoneNumber, type RoutingPage } from "../api";
import { t } from "../text";
import { RoutedNumbers } from "./RoutedNumbers";
import { RoutingConnectionPanel } from "./RoutingConnectionPanel";
import { RoutingRuleList } from "./RoutingRuleList";
import { TryRoutingCall } from "./TryRoutingCall";

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

/** Every saved agent of the account, page by page: any of them can take a routed call. */
async function allAgents(): Promise<Agent[]> {
  const first = await kitApi.agents(1);
  const pages = Math.max(1, Math.ceil(first.total / first.perPage));
  const rest = await Promise.all(Array.from({ length: pages - 1 }, (_, i) => kitApi.agents(i + 2)));
  return [first, ...rest].flatMap((p) => p.agents);
}

export function CallRoutingPage() {
  const [page, setPage] = useState<RoutingPage | null>(null);
  const [agents, setAgents] = useState<Agent[]>([]);
  const [failure, setFailure] = useState<string | null>(null);
  const manages = page?.canManage === true;

  const load = useCallback(async () => {
    try {
      setPage(await kitApi.routing());
      setFailure(null);
    } catch (err) {
      setFailure(message(err));
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  useEffect(() => {
    if (manages) allAgents().then(setAgents, (err) => setFailure(message(err)));
  }, [manages]);

  /** A number as saved after its switch changed; the page is read again, as routing the first number connects the account. */
  const changed = (n: PhoneNumber) => {
    setPage((p) => p && { ...p, numbers: p.numbers.map((x) => (x.id === n.id ? { ...x, agent: n.agent, routed: n.routed, syncState: n.syncState, syncError: n.syncError } : x)) });
    void load();
  };

  return (
    <section aria-labelledby="routing-title">
      <h1 id="routing-title">{t("routingTitle")}</h1>
      <p className="muted">{t("routingHelp")}</p>
      {failure && <p role="alert" className="error">{failure}</p>}
      {!page && !failure && <p aria-busy="true">{t("loading")}</p>}
      {page && (
        <>
          {!page.canManage && <p className="note">{t("routingOnlyManagers")}</p>}
          <RoutedNumbers numbers={page.numbers} canManage={page.canManage} onChanged={changed} />
          {page.connection && <RoutingConnectionPanel connection={page.connection} numbers={page.numbers} canManage={page.canManage} />}
          <RoutingRuleList rules={page.rules} numbers={page.numbers} agents={agents} limits={page.limits} canManage={page.canManage}
            onRules={(rules) => setPage((p) => p && { ...p, rules })} />
          <TryRoutingCall numbers={page.numbers} />
        </>
      )}
    </section>
  );
}
