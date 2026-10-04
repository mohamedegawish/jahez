/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** API root including the version prefix, e.g. http://localhost:8000/api/v1. No default. */
  readonly VITE_API_BASE_URL?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
