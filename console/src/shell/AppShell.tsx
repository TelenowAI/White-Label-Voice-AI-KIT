/**
 * The signed-in frame the customer console and the admin panel share: a side menu of links in groups, with whatever the
 * area puts above it (the account switcher) and below it (Sign out), beside the page. On a wide screen the menu stays
 * in view while the page scrolls; on a narrow one it folds behind a Menu button and opens over the page, and following
 * a link, Escape, the backdrop or its close button folds it again, with the focus back on the Menu button.
 *
 * The menu is one `nav` named by the area (`navLabel`) with the class `admin-nav`, which the invoice page's print
 * styles hide; its links are in the order given.
 *
 * Not here: which links an area has, in which groups (console/ConsoleArea.tsx, admin/AdminArea.tsx); the icons'
 * shapes (shell/navIcons.ts); the brand bar above the frame (App.tsx); how it all looks (styles.css, "The signed-in
 * frame").
 */
import { useCallback, useEffect, useId, useRef, useState, type ReactNode } from "react";
import { NavLink, useLocation } from "react-router-dom";
import { t, type TextKey } from "../text";
import { NAV_ICONS, type NavIcon } from "./navIcons";

/** One link of the side menu: where it goes, its words, and its icon. `end`: it is the open page only at exactly `to`. */
export type NavItem = { to: string; label: TextKey; icon?: NavIcon; end?: boolean };

/** A run of links under one heading; a group with no title (usually the first) has none shown. */
export type NavGroup = { title?: TextKey; links: NavItem[] };

export function AppShell({ navLabel, groups, top, bottom, children }: {
  navLabel: string;
  groups: NavGroup[];
  top?: ReactNode;
  bottom?: ReactNode;
  children: ReactNode;
}) {
  const [open, setOpen] = useState(false);
  const menuButton = useRef<HTMLButtonElement>(null);
  const sideId = useId();
  const { pathname } = useLocation();

  const close = useCallback(() => {
    setOpen(false);
    menuButton.current?.focus();
  }, []);

  // Another page (a link followed in the menu, or the browser's Back) folds the menu.
  useEffect(() => {
    setOpen(false);
  }, [pathname]);

  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") close();
    };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [open, close]);

  return (
    <div className={open ? "shell shell-open" : "shell"}>
      <div className="shell-menu-bar">
        <button
          ref={menuButton}
          type="button"
          className="secondary shell-menu-button"
          aria-expanded={open}
          aria-controls={sideId}
          onClick={() => setOpen(true)}
        >
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">
            <path d="M4 7h16M4 12h16M4 17h16" />
          </svg>
          {t("menuOpen")}
        </button>
      </div>
      {open && <div className="shell-backdrop" aria-hidden="true" onClick={close} />}
      <aside id={sideId} className="shell-side">
        {open && (
          <button type="button" className="secondary shell-close" onClick={close} autoFocus>
            {t("menuClose")}
          </button>
        )}
        {top}
        <nav className="admin-nav" aria-label={navLabel}>
          {groups.map((group, i) => (
            // Links straight in the group, not in a list: a page's own lists stay the only list items on the page.
            <div className="nav-group" key={group.title ?? `group-${i}`}>
              {group.title && <p className="nav-group-title">{t(group.title)}</p>}
              {group.links.map((link) => (
                <NavLink key={link.to} to={link.to} end={link.end}>
                  {link.icon && NAV_ICONS[link.icon] && (
                    <svg className="nav-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                      <path d={NAV_ICONS[link.icon]} />
                    </svg>
                  )}
                  {t(link.label)}
                </NavLink>
              ))}
            </div>
          ))}
        </nav>
        {bottom && <div className="shell-side-end">{bottom}</div>}
      </aside>
      <div className="shell-main">
        <div className="shell-page">{children}</div>
      </div>
    </div>
  );
}
