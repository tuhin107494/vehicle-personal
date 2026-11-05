"use client";

import { useEffect, useRef, useState } from "react";
import { Link2, Maximize, Minimize, Pause, Play, Volume2, VolumeX, type LucideIcon } from "lucide-react";
import { toast } from "sonner";
import { useT } from "@/lib/i18n";
import { VehicleScene } from "@/components/LoginPoster";

const VIDEO_SRC = "/videos/gari-becho-demo.mp4";
const YOUTUBE_URL = "https://youtu.be/LaxkjeS4gNY";

// iPhones can only make the video element itself full screen, and only through this Safari method
type IosVideo = HTMLVideoElement & { webkitEnterFullscreen?: () => void };

const clock = (seconds: number) => `${Math.floor(seconds / 60)}:${String(Math.floor(seconds % 60)).padStart(2, "0")}`;

function ControlButton({ icon: Icon, label, onClick }: { icon: LucideIcon; label: string; onClick: () => void }) {
  return (
    <button type="button" aria-label={label} onClick={onClick} className="flex size-9 shrink-0 items-center justify-center rounded-lg hover:bg-white/20 focus-visible:outline-2 focus-visible:outline-white">
      <Icon className="size-5" />
    </button>
  );
}

/** The hero's picture with a play button over it; pressing play swaps it for the demo film in the same space, and the file only downloads then. Clicking the film opens it on YouTube. */
export function LandingVideo() {
  const { t } = useT();
  const box = useRef<HTMLDivElement>(null);
  const video = useRef<HTMLVideoElement>(null);
  const [started, setStarted] = useState(false);
  const [playing, setPlaying] = useState(false);
  const [muted, setMuted] = useState(false);
  const [time, setTime] = useState(0);
  const [duration, setDuration] = useState(0);
  const [fullscreen, setFullscreen] = useState(false);

  useEffect(() => {
    const sync = () => setFullscreen(document.fullscreenElement === box.current);
    document.addEventListener("fullscreenchange", sync);
    return () => document.removeEventListener("fullscreenchange", sync);
  }, []);

  const play = () => video.current?.play().catch(() => toast.error(t("The video could not be played.")));
  const toggleMute = () => {
    if (video.current) video.current.muted = !video.current.muted;
  };
  const seek = (seconds: number) => {
    if (video.current) video.current.currentTime = seconds;
    setTime(seconds);
  };
  const toggleFullscreen = () => {
    if (document.fullscreenElement) void document.exitFullscreen();
    else if (document.fullscreenEnabled) void box.current?.requestFullscreen();
    else (video.current as IosVideo | null)?.webkitEnterFullscreen?.();
  };
  const copyLink = () => navigator.clipboard.writeText(YOUTUBE_URL).then(() => toast.success(t("Link copied.")), () => toast.error(t("The link could not be copied.")));

  return (
    <div ref={box} className="group/video relative mx-auto aspect-video w-full max-w-xl">
      <video
        ref={video}
        src={VIDEO_SRC}
        preload="none"
        playsInline
        aria-label={t("Gari Becho demo video")}
        onPlay={() => { setStarted(true); setPlaying(true); }}
        onPause={() => setPlaying(false)}
        onTimeUpdate={(e) => setTime(e.currentTarget.currentTime)}
        onLoadedMetadata={(e) => setDuration(e.currentTarget.duration)}
        onVolumeChange={(e) => setMuted(e.currentTarget.muted)}
        className={started ? "absolute inset-0 size-full rounded-xl bg-black object-contain shadow-sm ring-1 ring-emerald-200" : "hidden"}
      />

      {!started && (
        <button type="button" aria-label={t("Play demo video")} onClick={play} className="group absolute inset-0 flex items-end rounded-xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700">
          <VehicleScene className="w-full" />
          <span className="absolute left-1/2 top-1/2 flex size-16 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-emerald-700 text-white shadow-lg group-hover:bg-emerald-800">
            <Play className="size-7 translate-x-0.5 fill-current" />
          </span>
        </button>
      )}

      {started && (
        <>
          <a href={YOUTUBE_URL} target="_blank" rel="noopener noreferrer" aria-label={t("Watch on YouTube")} onClick={() => video.current?.pause()} className="absolute inset-x-0 bottom-11 top-0 rounded-t-xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700" />
          {/* hidden until hover only where a pointer can hover; touch screens keep it, and keyboard focus brings it back */}
          <div className="absolute inset-x-0 bottom-0 flex h-11 items-center gap-1 rounded-b-xl bg-black/70 px-1 text-white transition-opacity group-hover/video:opacity-100 has-focus-visible:opacity-100 [@media(hover:hover)]:opacity-0">
            <ControlButton icon={playing ? Pause : Play} label={t(playing ? "Pause video" : "Play video")} onClick={() => (playing ? video.current?.pause() : play())} />
            <input type="range" aria-label={t("Video position")} min={0} max={duration || 0} step={0.1} value={time} onChange={(e) => seek(Number(e.target.value))} className="min-w-0 flex-1 accent-emerald-500" />
            <span data-no-translate className="hidden shrink-0 px-1 text-xs tabular-nums sm:inline">{clock(time)} / {clock(duration)}</span>
            <ControlButton icon={muted ? VolumeX : Volume2} label={t(muted ? "Unmute" : "Mute")} onClick={toggleMute} />
            <ControlButton icon={Link2} label={t("Copy link")} onClick={copyLink} />
            <ControlButton icon={fullscreen ? Minimize : Maximize} label={t(fullscreen ? "Exit full screen" : "Full screen")} onClick={toggleFullscreen} />
          </div>
        </>
      )}
    </div>
  );
}
