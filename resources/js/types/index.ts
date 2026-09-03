export * from './auth';
export * from './navigation';
export * from './ui';

import type { Auth } from './auth';

export type Branding = {
    name: string;
    short_name: string;
    square_logo: string | null;
    rectangle_logo: string | null;
    source: string;
    project_id: string | null;
};

export type ActiveProjectContext = {
    project_id: string | null;
    project_name: string | null;
    source: string;
    subproject_ids: Record<string, string | null>;
    subproject_sources: Record<string, string>;
    message?: string | null;
};

export type AppPageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    name: string;
    auth: Auth;
    branding: Branding;
    activeProjectContext: ActiveProjectContext | null;
    sidebarOpen: boolean;
    [key: string]: unknown;
};
