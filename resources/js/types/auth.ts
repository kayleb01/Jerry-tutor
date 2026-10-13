export type Role = 'admin' | 'tutor' | 'student' | 'guardian';

export type User = {
    id: number;
    name: string;
    email: string | null;
    username: string | null;
    role: Role;
};

export type Auth = {
    user: User | null;
};
