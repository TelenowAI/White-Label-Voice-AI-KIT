/**
 * The small line icons beside the side menu's links (shell/AppShell.tsx draws them, 24×24, stroked in the text colour or,
 * for the page that is open, the brand colour). Each is one SVG path; a link names its icon, and a link with no icon, or
 * a name not here, is drawn without one.
 *
 * Not here: which link has which icon (console/ConsoleArea.tsx, admin/AdminArea.tsx), how they are drawn (AppShell.tsx,
 * styles.css "The signed-in frame").
 */
export const NAV_ICONS = {
  home: "M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z",
  insights: "M4 20h16M7 16v-4M12 16V8M17 16V5",
  agents: "M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21a8 8 0 0 1 16 0",
  knowledge: "M5 4h11a3 3 0 0 1 3 3v13H8a3 3 0 0 1-3-3zM5 17a3 3 0 0 1 3-3h11M9 8h6",
  voices: "M4 10v4M8 7v10M12 4v16M16 8v8M20 11v2",
  calls: "M5 4h3.5l2 5-2.3 1.4a11 11 0 0 0 5.4 5.4L15 13.5l5 2V19a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z",
  phone: "M4 14v-2a8 8 0 0 1 16 0v2M4 14h3v6H5a1 1 0 0 1-1-1zM20 14h-3v6h2a1 1 0 0 0 1-1z",
  campaigns: "M4 10v4a1 1 0 0 0 1 1h2l6 4V5L7 9H5a1 1 0 0 0-1 1zM17 9a4 4 0 0 1 0 6",
  followups: "M5 4h14v16H5zM9 12l2 2 4-4",
  memory: "M4 6h16v13H4zM9 9a2 2 0 1 1 0 4 2 2 0 0 1 0-4zM6 17a3 3 0 0 1 6 0M14 10h3M14 14h3",
  numbers: "M5 9h14M5 15h14M10 4 8 20M16 4l-2 16",
  routing: "M6 3v5a5 5 0 0 0 5 5h7M15 10l3 3-3 3M6 21v-5",
  dnc: "M12 3a9 9 0 1 1 0 18 9 9 0 0 1 0-18zM5.6 5.6l12.8 12.8",
  consent: "M12 3l7 3v6c0 4.5-3 7.7-7 9-4-1.3-7-4.5-7-9V6zM9 12l2 2 4-4",
  wallet: "M4 7h15a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1zM4 7l11-3v3M16 13h1",
  team: "M9 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM3 20a6 6 0 0 1 12 0M16 5.5a3 3 0 0 1 0 5.5M17 14a5 5 0 0 1 4 6",
  account: "M12 3a9 9 0 1 1 0 18 9 9 0 0 1 0-18zM12 7a3 3 0 1 1 0 6 3 3 0 0 1 0-6zM6.7 18.2a6 6 0 0 1 10.6 0",
  dashboard: "M4 4h7v7H4zM13 4h7v4h-7zM13 10h7v10h-7zM4 13h7v7H4z",
  platform: "M4 5h16v5H4zM4 14h16v5H4zM8 7.5h.01M8 16.5h.01",
  costs: "M12 4v16M7 20h10M5 8h14M5 8l-2.5 6a2.5 2.5 0 0 0 5 0zM19 8l-2.5 6a2.5 2.5 0 0 0 5 0z",
  pricing: "M3 12V4h8l10 10-8 8zM7.5 7.5h.01",
  payments: "M3 6h18v12H3zM3 10h18M7 15h4",
  invoices: "M6 3h9l4 4v14H6zM14 3v5h5M9 13h6M9 17h6",
  bank: "M3 10l9-6 9 6M5 10v8M10 10v8M14 10v8M19 10v8M3 20h18",
  catalog: "M12 3l9 5-9 5-9-5zM3 13l9 5 9-5",
  starters: "M4 4h16v6H4zM4 14h7v6H4zM15 14h5v6h-5z",
  signups: "M10 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM3 21a7 7 0 0 1 14 0M19 8v6M16 11h6",
  email: "M3 6h18v12H3zM3 7l9 6 9-6",
  sms: "M4 5h16v11H9l-5 4z",
  sso: "M14 4a6 6 0 1 1-5.2 9L3 18.8V21h3v-2h2v-2h2l1.8-1.8A6 6 0 0 1 14 4zM16 8h.01",
  captcha: "M12 3l7 3v6c0 4.5-3 7.7-7 9-4-1.3-7-4.5-7-9V6z",
  website: "M12 3a9 9 0 1 1 0 18 9 9 0 0 1 0-18zM3 12h18M12 3c2.5 2.5 3.5 5.5 3.5 9s-1 6.5-3.5 9c-2.5-2.5-3.5-5.5-3.5-9s1-6.5 3.5-9z",
  staff: "M5 4h14v17H5zM12 9a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5zM8.5 18a3.5 3.5 0 0 1 7 0M10 4v2h4V4",
  audit: "M12 3a9 9 0 1 1 0 18 9 9 0 0 1 0-18zM12 7v5l3 2",
} as const;

export type NavIcon = keyof typeof NAV_ICONS;
