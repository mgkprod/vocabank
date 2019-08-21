<?php

namespace App\Http\Controllers;

use App\Jobs\ConvertToMP3Job;
use App\Jobs\GenerateWaveformJob;
use App\Jobs\MakePublicJob;
use App\Jobs\YoutubeDlJob;
use App\Models\Sample;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class SampleController extends Controller
{
    public function index()
    {
        return redirect()->route('samples.recent');
    }

    public function recent()
    {
        $samples = Sample::with('user')->public()->orderBy('created_at', 'DESC')->paginate(15);

        if (request()->ajax()) {
            return $samples;
        }

        return view('sample.index', compact('samples'))->with('filter', 'recent');
    }

    public function popular()
    {
        $samples = Sample::with('user')->public()->orderByViews()->paginate(15);

        if (request()->ajax()) {
            return $samples;
        }

        return view('sample.index', compact('samples'))->with('filter', 'popular');
    }

    public function search(Request $request)
    {
        if ($request->q) {
            $samples = Sample::with('user')->public();

            if (!$request->tag) {
                $samples = $samples
                    ->whereHas('tags', function ($query) use ($request) { return $query->where('name', 'like', '%' . $request->q . '%'); })
                    ->orWhere('name', 'like', '%' . $request->q . '%')
                    ->orWhere('description', 'like', '%' . $request->q . '%');
            } else {
                $samples = $samples->whereHas('tags', function ($query) use ($request) { return $query->where('name', $request->q); });
            }

            $samples = $samples->paginate(15);

            return view('sample.index', compact('samples'))->with('q', $request->q);
        } else {
            return view('sample.index');
        }
    }

    public function random()
    {
        $sample = Sample::public()->limit(1)->inRandomOrder()->first();

        return redirect()->route('samples.show', $sample);
    }

    public function create()
    {
        return view('sample.create');
    }

    public function createURL()
    {
        return view('sample.create_url');
    }

    public function preflight()
    {
        request()->validate([
            'audio' => ['required', 'file', 'max:10240', 'mimetypes:audio/mpeg,audio/mp3,audio/wav,audio/ogg'],
        ]);

        $sample = auth()->user()->samples()->create(
            ['name' => request()->file('audio')->getClientOriginalName()]
        );

        $audio_name = $sample->id . '_audio_' . time() . '.' . request()->audio->getClientOriginalExtension();
        $storage_path = request()->file('audio')->storeAs('temp', $audio_name, 'local');
        $sample->audio = $storage_path;
        $sample->save();

        ConvertToMP3Job::withChain([
            new GenerateWaveformJob($sample->id),
            new MakePublicJob($sample->id),
        ])->dispatch($sample->id);

        return $sample;
    }

    public function preflightURL()
    {
        request()->validate([
            'url' => ['required'],
        ]);

        try {
            $process = new Process([
                'youtube-dl',
                request()->url,
                '--skip-download',
                '--dump-json',
                '--no-playlist',
            ]);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }
        } catch (\Exception $e) {
            return response()->json(['errors' => ['url' => ['Déso, aucune information n\'a été trouvée']]], 422);
        }

        $ytdl_dump = json_decode($process->getOutput());

        if ($ytdl_dump->extractor !== 'youtube' && $ytdl_dump->extractor !== 'soundcloud') {
            if (!isset($ytdl_dump->duration)) {
                return response()->json(['errors' => ['url' => ['Déso, des informations ont été trouvées mais le site n\'est pas explicitement autorisé (poke the dev!)']]], 422);
            }
        }

        if ($ytdl_dump->duration < 1 || $ytdl_dump->duration >= 5 * 60) { // 5 minutes
            return response()->json(['errors' => ['url' => ['Le sample doit faire moins de 5 minutes, abuse pas']]], 422);
        }

        $sample = auth()->user()->samples()->create([
            'name'        => $ytdl_dump->alt_title ?? $ytdl_dump->title,
            'description' => 'Source : ' . $ytdl_dump->webpage_url . ' (' . $ytdl_dump->extractor . ')',
        ]);

        if (isset($ytdl_dump->tags) && count($ytdl_dump->tags)) {
            foreach ($ytdl_dump->tags as $tag) {
                Tag::firstOrCreate(['name' => $tag])->samples()->attach($sample);
            }
        }

        if (isset($ytdl_dump->thumbnail)) {
            if (!Storage::disk('public')->exists('images/')) {
                Storage::disk('public')->makeDirectory('images/', 0775, true);
            }

            $thumbnail_name = $sample->id . '_thumbnail_' . time() . '.jpg';

            Image::make(file_get_contents($ytdl_dump->thumbnail))->fit(300, 300)->save(Storage::disk('public')->path('images/' . $thumbnail_name));
            $sample->thumbnail = 'images/' . $thumbnail_name;
            $sample->save();
        }

        YoutubeDlJob::withChain([
            new GenerateWaveformJob($sample->id),
            new MakePublicJob($sample->id),
        ])->dispatch($sample->id, request()->url);

        return $sample;
    }

    public function show(Sample $sample)
    {
        return view('sample.show', compact('sample'));
    }

    public function next(Sample $sample)
    {
        $next_sample = $sample->next;

        if ($next_sample) {
            return redirect()->route('samples.show', $next_sample);
        }

        return redirect()->route('home');
    }

    public function prev(Sample $sample)
    {
        $prev_sample = $sample->prev;

        if ($prev_sample) {
            return redirect()->route('samples.show', $prev_sample);
        }

        return redirect()->route('home');
    }

    public function iframe(Sample $sample)
    {
        $sample->user; // Preload

        return view('sample.iframe', compact('sample'));
    }

    public function listen(Sample $sample)
    {
        views($sample)
            ->delayInSession(1)
            ->record();

        return response()->file(Storage::disk('public')->path($sample->audio));
    }

    public function download(Sample $sample)
    {
        return response()->download(Storage::disk('public')->path($sample->audio));
    }

    public function edit(Sample $sample)
    {
        abort_if(($sample->user != auth()->user()) && (!auth()->user()->hasRole('admin')), 403);

        $tags = $sample->tags->pluck('name'); // Preload

        return view('sample.edit', compact('sample', 'tags'));
    }

    public function update(Sample $sample)
    {
        abort_if(($sample->user != auth()->user()) && (!auth()->user()->hasRole('admin')), 403);

        request()->validate([
            'name'      => ['required', 'min:3', 'max:60', 'unique:samples,name,' . $sample->id],
            'tags'      => ['nullable', 'array'],
            'thumbnail' => ['nullable', 'mimes:jpeg,bmp,png,gif,jpg', 'max:2048'],
        ]);

        $sample->name = request()->name;
        $sample->description = request()->description;

        if (request()->hasFile('thumbnail')) {
            if (!Storage::disk('public')->exists('images/')) {
                Storage::disk('public')->makeDirectory('images/', 0775, true);
            }

            $thumbnail_name = $sample->id . '_thumbnail_' . time() . '.jpg';

            Image::make(request()->thumbnail)->fit(300, 300)->save(Storage::disk('public')->path('images/' . $thumbnail_name));
            $sample->thumbnail = 'images/' . $thumbnail_name;
        }

        $sample->tags()->detach();
        foreach (request()->tags ?? [] as $tag) {
            Tag::firstOrCreate(['name' => $tag])->samples()->attach($sample);
        }

        $sample->save();

        return $sample;
    }

    public function destroy(Sample $sample)
    {
        abort_if(!auth()->user()->hasRole('admin'), 403);

        $sample->delete();

        return redirect('/');
    }
}
