/**
 * The console's shell: two route trees in one app — `/console/*` for the operator's customers and `/admin/*` for
 * the operator — `/reset/:token` (a password reset link, for both), `/signup` with `/signup/verify/:token` and
 * `/signin/:token` (signing up, and an emailed sign-in link), and `/setup`, the setup wizard, where every visitor is
 * sent while the kit is not installed. Once it is, `/setup` opens the admin panel (the wizard's last step
 * lands there). The brand is applied before anything
 * renders, and again whenever the wizard changes it.
 *
 * Not here: the customer console (console/), the admin panel (admin/), the wizard's steps (setup/, served by the
 * backend).
 */
import { useCallback, useEffect, useState } from "react";
import { Navigate, Route, Routes, useLocation } from "react-router-dom";
import { AdminArea } from "./admin/AdminArea";
import { kitApi, type Brand, type Health } from "./api";
import { applyBrand } from "./brand";
import { ConsoleArea } from "./console/ConsoleArea";
import { ResetPassword } from "./setup/ResetPassword";
import { SignInWithLink } from "./setup/SignInWithLink";
import { SignUp } from "./setup/SignUp";
import { VerifySignup } from "./setup/VerifySignup";
import { SetupWizard } from "./setup/SetupWizard";
import { t } from "./text";

type State = { phase: "loading" } | { phase: "down"; reason: string } | { phase: "ready"; health: Health; brand: Brand };

export function App() {
  const [state, setState] = useState<State>({ phase: "loading" });

  const load = useCallback(async () => {
    try {
      const [health, brand] = await Promise.all([kitApi.health(), kitApi.brand()]);
      applyBrand(brand);
      setState({ phase: "ready", health, brand });
    } catch (e: unknown) {
      setState({ phase: "down", reason: e instanceof Error ? e.message : String(e) });
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  if (state.phase === "loading") return <main aria-busy="true" />;
  if (state.phase === "down") {
    return (
      <main role="alert">
        <h1>{t("notReachable")}</h1>
        <p>{state.reason}</p>
      </main>
    );
  }
  return <Shell health={state.health} brand={state.brand} reload={load} />;
}

function Shell({ health, brand, reload }: { health: Health; brand: Brand; reload: () => Promise<void> }) {
  const { pathname } = useLocation();
  if (!health.installed && !pathname.startsWith("/setup")) return <Navigate to="/setup" replace />;
  if (health.installed && pathname.startsWith("/setup")) return <Navigate to="/admin" replace />;
  return (
    <main>
      <BrandBar brand={brand} />
      <Routes>
        <Route path="/setup/*" element={<SetupWizard brand={brand} onChanged={() => void reload()} onInstalled={() => void reload()} />} />
        <Route path="/console/*" element={<ConsoleArea />} />
        <Route path="/admin/*" element={<AdminArea />} />
        <Route path="/reset/:token" element={<ResetPassword />} />
        <Route path="/signup" element={<SignUp />} />
        <Route path="/signup/verify/:token" element={<VerifySignup />} />
        <Route path="/signin/:token" element={<SignInWithLink />} />
        <Route path="*" element={<Navigate to="/console" replace />} />
      </Routes>
    </main>
  );
}

function BrandBar({ brand }: { brand: Brand }) {
  return (
    <header className="brand-bar">
      {brand.logoUrl ? <img src={brand.logoUrl} alt={brand.name} /> : <strong>{brand.name}</strong>}
    </header>
  );
}
