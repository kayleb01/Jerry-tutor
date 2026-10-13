import { cn } from '@/lib/utils';

type AppLogoImageProps = {
    className?: string;
    /** Always sit the logo on a white panel, e.g. on an indigo or dark background. */
    onDark?: boolean;
    /** Show only the crest (crown and shield), for tight spaces like the collapsed sidebar. */
    crest?: boolean;
};

/**
 * The logo (transparent PNG). Brand rule: it only ever sits on a light
 * ground, so in dark mode, or when `onDark` is set, it gets a white panel.
 */
export default function AppLogoImage({
    className,
    onDark = false,
    crest = false,
}: AppLogoImageProps) {
    const image = crest
        ? { src: '/images/brand/logo-crest.png', width: 420, height: 558 }
        : { src: '/images/brand/logo-tight.png', width: 1270, height: 560 };

    return (
        <span
            className={cn(
                'inline-flex rounded-md',
                onDark
                    ? 'bg-white px-3 py-2'
                    : 'dark:bg-white dark:px-3 dark:py-2',
                className,
            )}
        >
            <img
                src={image.src}
                alt="Jerry Great Academy"
                width={image.width}
                height={image.height}
                className="h-auto w-full"
            />
        </span>
    );
}
