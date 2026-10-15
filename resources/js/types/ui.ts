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

export type LessonState = 'upcoming' | 'in_progress' | 'finished' | 'cancelled';

export type DashboardLesson = {
    id: number;
    className: string;
    time: string;
    location: string | null;
    minutes: number;
    state: LessonState;
    attendance: 'present' | 'late' | 'absent' | 'excused' | null;
    canReport: boolean;
    reported: boolean;
};

export type DashboardDay = {
    key: string;
    name: string;
    date: string;
    minutes: number;
    lessons: DashboardLesson[];
};

export type Strand = {
    key: 'number' | 'algebra' | 'statistics' | 'geometry';
    name: string;
    summary: string;
    quizSlug: string | null;
    lastScore: string | null;
};

export type StudentStats = {
    attendance: number | null;
    onTime: number | null;
    classTime: string;
    lessonsThisWeek: number;
};
