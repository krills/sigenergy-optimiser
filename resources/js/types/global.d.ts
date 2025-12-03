import { PageProps as InertiaPageProps } from '@inertiajs/core';
import { PageProps as AppPageProps } from './sigenergy';

declare global {
  namespace App {
    interface PageProps extends InertiaPageProps, AppPageProps {}
  }
}

declare module '@inertiajs/core' {
  interface PageProps extends AppPageProps {}
}

// Extend the global Window interface if needed
declare global {
  interface Window {
    // Add any global properties you might need
  }
}

// Environment variables
interface ImportMetaEnv {
  readonly VITE_APP_NAME: string;
  // Add other env variables as needed
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}