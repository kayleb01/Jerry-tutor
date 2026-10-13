import AppLogoImage from '@/components/app-logo-image';

type AppLogoProps = {
    className?: string;
};

/** The academy logo at its usual header size. */
export default function AppLogo({ className }: AppLogoProps) {
    return <AppLogoImage className={className ?? 'w-36'} />;
}
