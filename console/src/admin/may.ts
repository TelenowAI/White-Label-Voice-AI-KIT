/**
 * What the signed-in staff role may do in the admin panel (the session's `abilities`), for pages to offer only that:
 * AdminArea provides it, pages ask `useMay()("money")`. The backend refuses what the role may not do whatever the
 * console shows; a session from a backend that sends no abilities shows everything.
 */
import { createContext, useContext } from "react";
import type { User } from "../api";

export type May = (ability: string) => boolean;

export const MayContext = createContext<May>(() => true);

export function mayFor(user: User): May {
  return (ability) => user.abilities === undefined || user.abilities.includes(ability);
}

export function useMay(): May {
  return useContext(MayContext);
}
