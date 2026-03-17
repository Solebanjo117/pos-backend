import { createRequire } from 'module';
import fs from 'fs';
import path from 'path';
import { execSync, spawnSync } from 'child_process';
import { fileURLToPath } from 'url';

const require = createRequire(import.meta.url);

const __dirname = path.dirname(fileURLToPath(import.meta.url));

const VIDEO_PATH = path.join(__dirname, "Meet_ ptx-dcqr-eqo - Brave 2026-03-09 23-36-35.mp4");
const AUDIO_OUTPUT = path.join(__dirname, "meet_audio.mp3");
const TRANSCRIPT_OUTPUT = path.join(__dirname, "meet_transcription.txt");

async function getFfmpegPath() {
  try {
    const ffmpegInstaller = require('@ffmpeg-installer/ffmpeg');
    console.log('ffmpeg path:', ffmpegInstaller.path);
    return ffmpegInstaller.path;
  } catch(e) {
    console.error('Could not find @ffmpeg-installer/ffmpeg:', e.message);
    // Try global install
    const result = spawnSync('where', ['ffmpeg'], { encoding: 'utf8' });
    if (result.stdout) {
      return result.stdout.trim().split('\n')[0];
    }
    throw new Error('ffmpeg not found');
  }
}

async function extractAudio(ffmpegPath) {
  console.log('\n[1/3] Extracting audio from video...');
  console.log('Video:', VIDEO_PATH);
  console.log('Output:', AUDIO_OUTPUT);

  if (fs.existsSync(AUDIO_OUTPUT)) {
    console.log('Audio file already exists, skipping extraction...');
    return;
  }

  const result = spawnSync(ffmpegPath, [
    '-i', VIDEO_PATH,
    '-vn',
    '-acodec', 'mp3',
    '-ar', '16000',
    '-ac', '1',
    '-b:a', '64k',
    AUDIO_OUTPUT,
    '-y'
  ], { encoding: 'utf8', maxBuffer: 100 * 1024 * 1024, timeout: 600000 });

  if (result.status !== 0) {
    console.error('ffmpeg stderr:', result.stderr);
    throw new Error('ffmpeg failed with status ' + result.status);
  }

  const stats = fs.statSync(AUDIO_OUTPUT);
  console.log(`Audio extracted. Size: ${(stats.size / 1024 / 1024).toFixed(2)} MB`);
}

async function transcribeWithGroq(audioPath) {
  const GROQ_API_KEY = process.env.GROQ_API_KEY;
  if (!GROQ_API_KEY) {
    throw new Error('GROQ_API_KEY environment variable not set.\nGet a free API key at https://console.groq.com and run:\n$env:GROQ_API_KEY = "your-api-key"');
  }

  const stats = fs.statSync(audioPath);
  const fileSizeMB = stats.size / 1024 / 1024;
  console.log(`\n[2/3] Transcribing audio (${fileSizeMB.toFixed(2)} MB) with Groq Whisper...`);
  console.log('Language: Spanish (es)');

  // Groq allows up to 25MB. If larger, we need to chunk.
  if (fileSizeMB > 24) {
    console.log('File is large, will split into chunks...');
    return await transcribeInChunks(audioPath, GROQ_API_KEY);
  }

  const audioBuffer = fs.readFileSync(audioPath);
  const formData = new FormData();
  const blob = new Blob([audioBuffer], { type: 'audio/mpeg' });
  formData.append('file', blob, 'audio.mp3');
  formData.append('model', 'whisper-large-v3');
  formData.append('language', 'es');
  formData.append('response_format', 'text');

  const response = await fetch('https://api.groq.com/openai/v1/audio/transcriptions', {
    method: 'POST',
    headers: { 'Authorization': `Bearer ${GROQ_API_KEY}` },
    body: formData
  });

  if (!response.ok) {
    const errText = await response.text();
    throw new Error(`Groq API error ${response.status}: ${errText}`);
  }

  return await response.text();
}

async function transcribeInChunks(audioPath, apiKey) {
  const ffmpegInstaller = require('@ffmpeg-installer/ffmpeg');
  const ffmpegPath = ffmpegInstaller.path;
  const chunksDir = path.join(path.dirname(audioPath), 'audio_chunks');
  
  if (!fs.existsSync(chunksDir)) fs.mkdirSync(chunksDir, { recursive: true });

  console.log('Splitting audio into 10-minute chunks...');
  const result = spawnSync(ffmpegPath, [
    '-i', audioPath,
    '-f', 'segment',
    '-segment_time', '600',
    '-c', 'copy',
    path.join(chunksDir, 'chunk_%03d.mp3'),
    '-y'
  ], { encoding: 'utf8', timeout: 300000 });

  const chunks = fs.readdirSync(chunksDir).filter(f => f.endsWith('.mp3')).sort();
  console.log(`Created ${chunks.length} chunks`);

  let fullTranscription = '';
  for (let i = 0; i < chunks.length; i++) {
    const chunkPath = path.join(chunksDir, chunks[i]);
    console.log(`Transcribing chunk ${i+1}/${chunks.length}: ${chunks[i]}`);

    const audioBuffer = fs.readFileSync(chunkPath);
    const formData = new FormData();
    const blob = new Blob([audioBuffer], { type: 'audio/mpeg' });
    formData.append('file', blob, chunks[i]);
    formData.append('model', 'whisper-large-v3');
    formData.append('language', 'es');
    formData.append('response_format', 'text');

    const response = await fetch('https://api.groq.com/openai/v1/audio/transcriptions', {
      method: 'POST',
      headers: { 'Authorization': `Bearer ${apiKey}` },
      body: formData
    });

    if (!response.ok) {
      const errText = await response.text();
      console.error(`Groq error on chunk ${i+1}: ${response.status} - ${errText}`);
      continue;
    }

    const text = await response.text();
    fullTranscription += text + '\n';
    
    // Rate limit respect
    await new Promise(r => setTimeout(r, 1000));
  }

  return fullTranscription;
}

async function main() {
  console.log('=== Meet Transcription Tool ===');
  console.log('Video:', VIDEO_PATH);

  if (!fs.existsSync(VIDEO_PATH)) {
    throw new Error(`Video file not found: ${VIDEO_PATH}`);
  }

  const ffmpegPath = await getFfmpegPath();
  await extractAudio(ffmpegPath);
  const transcription = await transcribeWithGroq(AUDIO_OUTPUT);

  console.log('\n[3/3] Saving transcription...');
  fs.writeFileSync(TRANSCRIPT_OUTPUT, transcription, 'utf8');
  console.log(`Saved to: ${TRANSCRIPT_OUTPUT}`);

  console.log('\n=== TRANSCRIPTION (first 3000 chars) ===');
  console.log(transcription.substring(0, 3000));
  console.log('\n[DONE] Full transcription saved to:', TRANSCRIPT_OUTPUT);
}

main().catch(e => {
  console.error('\nERROR:', e.message);
  process.exit(1);
});
