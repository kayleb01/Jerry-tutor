export type Option = { value: number | string; label: string };

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type Toast = { type: 'success' | 'error'; message: string };
