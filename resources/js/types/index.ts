// Legacy types (deprecated - use models.ts instead)
export * from './sigenergy';

// New strict typed models
export * from './models';
export * from './converters';

// Common types for the application
export interface User {
  id: number;
  name: string;
  email: string;
}

// Global page props that might be available on all pages
export interface InertiaSharedProps {
  auth?: {
    user?: User;
  };
  flash?: {
    message?: string;
    error?: string;
  };
}

// Helper type for React components with children
export interface WithChildren {
  children?: React.ReactNode;
}