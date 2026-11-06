/**
 * The console's build and dev server. In development `/api` is proxied to a running kit backend
 * (KIT_API_URL, default a local `php artisan serve`); in a release the backend serves the built files itself,
 * on the same origin, so nothing is proxied.
 */
/// <reference types="vitest/config" />
import react from "@vitejs/plugin-react";
import { defineConfig } from "vite";

export default defineConfig({
  plugins: [react()],
  server: {
    proxy: { "/api": process.env.KIT_API_URL ?? "http://127.0.0.1:8000" },
  },
  test: {
    environment: "jsdom",
  },
});
