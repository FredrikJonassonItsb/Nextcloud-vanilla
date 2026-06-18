import { apiClient, ocsGet, webdavClient } from './client';
import type { RecentFile, FileInfo, Activity } from '../types';

// Fetch recent files from activity stream
export const getRecentFiles = async (userId: string, limit: number = 10): Promise<RecentFile[]> => {
  try {
    // Get recent file activities
    const activities = await ocsGet<Activity[]>('/ocs/v2.php/apps/activity/api/v2/activity/files', {
      since: 0,
      limit: limit * 2, // Get more to filter
    });

    // Map activities to recent files and deduplicate
    const filesMap = new Map<string, RecentFile>();

    for (const activity of activities) {
      if (activity.object_type === 'files' && activity.object_name) {
        const key = activity.object_id.toString();

        if (!filesMap.has(key)) {
          filesMap.set(key, {
            id: activity.object_id,
            name: activity.object_name.split('/').pop() || activity.object_name,
            path: activity.object_name,
            type: 'file',
            mimeType: guessMimeType(activity.object_name),
            size: 0,
            modified: activity.datetime,
            etag: '',
            permissions: 'RGDNVW',
            favorite: false,
            isShared: activity.type.includes('shared'),
            isFederated: false,
            activity: {
              type: mapActivityType(activity.type),
              timestamp: activity.datetime,
              actor: activity.subject.split(' ')[0], // Extract actor from subject
            },
          });
        }
      }
    }

    // Return limited results
    return Array.from(filesMap.values()).slice(0, limit);
  } catch (error) {
    console.error('Failed to fetch recent files:', error);

    // Fallback: try to get files from WebDAV
    return getRecentFilesFromWebDAV(userId, limit);
  }
};

// Fallback: Get recent files using WebDAV
const getRecentFilesFromWebDAV = async (userId: string, limit: number): Promise<RecentFile[]> => {
  try {
    const doc = await webdavClient.listFiles('/');

    const files: RecentFile[] = [];
    const responses = doc.getElementsByTagNameNS('DAV:', 'response');

    for (const resp of Array.from(responses)) {
      if (files.length >= limit) break;

      const href = resp.getElementsByTagNameNS('DAV:', 'href')[0]?.textContent || '';
      const resourceType = resp.getElementsByTagNameNS('DAV:', 'resourcetype')[0];
      const isDirectory = !!resourceType?.getElementsByTagNameNS('DAV:', 'collection')[0];

      if (isDirectory) continue; // Skip directories

      const lastModified =
        resp.getElementsByTagNameNS('DAV:', 'getlastmodified')[0]?.textContent || '';
      const contentType =
        resp.getElementsByTagNameNS('DAV:', 'getcontenttype')[0]?.textContent || '';
      const contentLength =
        resp.getElementsByTagNameNS('DAV:', 'getcontentlength')[0]?.textContent || '0';
      const etag = resp.getElementsByTagNameNS('DAV:', 'getetag')[0]?.textContent || '';
      const fileId =
        resp.getElementsByTagNameNS('http://owncloud.org/ns', 'fileid')[0]?.textContent || '';
      const permissions =
        resp.getElementsByTagNameNS('http://owncloud.org/ns', 'permissions')[0]?.textContent || '';
      const favorite =
        resp.getElementsByTagNameNS('http://owncloud.org/ns', 'favorite')[0]?.textContent === '1';
      const shareTypes =
        resp.getElementsByTagNameNS('http://owncloud.org/ns', 'share-types')[0]?.textContent || '';

      // Extract path from href
      const basePath = `/remote.php/dav/files/${encodeURIComponent(userId)}`;
      let path = decodeURIComponent(href);
      if (path.startsWith(basePath)) {
        path = path.substring(basePath.length);
      }

      const name = path.split('/').pop() || path;

      files.push({
        id: parseInt(fileId, 10) || 0,
        name,
        path,
        type: 'file',
        mimeType: contentType,
        size: parseInt(contentLength, 10),
        modified: new Date(lastModified).toISOString(),
        etag,
        permissions,
        favorite,
        shareTypes: shareTypes ? shareTypes.split(',').map(Number) : undefined,
        isShared: !!shareTypes,
        isFederated: false,
        activity: {
          type: 'modified',
          timestamp: new Date(lastModified).toISOString(),
        },
      });
    }

    // Sort by modification date
    files.sort((a, b) => new Date(b.modified).getTime() - new Date(a.modified).getTime());

    return files;
  } catch (error) {
    console.error('Failed to fetch files from WebDAV:', error);
    return [];
  }
};

// Map activity type string to our enum
const mapActivityType = (
  type: string
): 'created' | 'modified' | 'shared' | 'downloaded' => {
  if (type.includes('created')) return 'created';
  if (type.includes('shared')) return 'shared';
  if (type.includes('downloaded')) return 'downloaded';
  return 'modified';
};

// Guess MIME type from filename
const guessMimeType = (filename: string): string => {
  const ext = filename.split('.').pop()?.toLowerCase();

  const mimeTypes: Record<string, string> = {
    pdf: 'application/pdf',
    doc: 'application/msword',
    docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    xls: 'application/vnd.ms-excel',
    xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ppt: 'application/vnd.ms-powerpoint',
    pptx: 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    txt: 'text/plain',
    md: 'text/markdown',
    jpg: 'image/jpeg',
    jpeg: 'image/jpeg',
    png: 'image/png',
    gif: 'image/gif',
    svg: 'image/svg+xml',
    mp3: 'audio/mpeg',
    mp4: 'video/mp4',
    zip: 'application/zip',
    json: 'application/json',
    xml: 'application/xml',
    html: 'text/html',
    css: 'text/css',
    js: 'application/javascript',
  };

  return mimeTypes[ext || ''] || 'application/octet-stream';
};

// Get file info by path
export const getFileInfo = async (userId: string, path: string): Promise<FileInfo> => {
  try {
    const response = await apiClient.request({
      method: 'PROPFIND',
      url: `/remote.php/dav/files/${encodeURIComponent(userId)}${path}`,
      headers: {
        'Content-Type': 'application/xml',
        Depth: '0',
      },
      data: `<?xml version="1.0"?>
        <d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">
          <d:prop>
            <d:getlastmodified />
            <d:getetag />
            <d:getcontenttype />
            <d:getcontentlength />
            <d:resourcetype />
            <oc:fileid />
            <oc:permissions />
            <oc:favorite />
            <nc:has-preview />
            <oc:share-types />
            <oc:owner-display-name />
          </d:prop>
        </d:propfind>`,
    });

    const parser = new DOMParser();
    const doc = parser.parseFromString(response.data, 'application/xml');
    const resp = doc.getElementsByTagNameNS('DAV:', 'response')[0];

    const resourceType = resp.getElementsByTagNameNS('DAV:', 'resourcetype')[0];
    const isDirectory = !!resourceType?.getElementsByTagNameNS('DAV:', 'collection')[0];

    return {
      id: parseInt(
        resp.getElementsByTagNameNS('http://owncloud.org/ns', 'fileid')[0]?.textContent || '0',
        10
      ),
      name: path.split('/').pop() || path,
      path,
      type: isDirectory ? 'directory' : 'file',
      mimeType:
        resp.getElementsByTagNameNS('DAV:', 'getcontenttype')[0]?.textContent ||
        (isDirectory ? 'inode/directory' : 'application/octet-stream'),
      size: parseInt(
        resp.getElementsByTagNameNS('DAV:', 'getcontentlength')[0]?.textContent || '0',
        10
      ),
      modified: new Date(
        resp.getElementsByTagNameNS('DAV:', 'getlastmodified')[0]?.textContent || ''
      ).toISOString(),
      etag: resp.getElementsByTagNameNS('DAV:', 'getetag')[0]?.textContent || '',
      permissions:
        resp.getElementsByTagNameNS('http://owncloud.org/ns', 'permissions')[0]?.textContent || '',
      favorite:
        resp.getElementsByTagNameNS('http://owncloud.org/ns', 'favorite')[0]?.textContent === '1',
      ownerDisplayName:
        resp.getElementsByTagNameNS('http://owncloud.org/ns', 'owner-display-name')[0]
          ?.textContent || undefined,
      isShared:
        !!resp.getElementsByTagNameNS('http://owncloud.org/ns', 'share-types')[0]?.textContent,
      isFederated: false,
    };
  } catch (error) {
    console.error('Failed to get file info:', error);
    throw error;
  }
};

// Get file download URL
export const getFileDownloadUrl = (userId: string, path: string): string => {
  return `${window.location.origin}/remote.php/dav/files/${encodeURIComponent(userId)}${path}`;
};

// Get file preview URL
export const getFilePreviewUrl = (fileId: number, width: number = 256, height: number = 256): string => {
  return `${window.location.origin}/index.php/core/preview?fileId=${fileId}&x=${width}&y=${height}&a=true`;
};

// Open file in Nextcloud
export const openFileInNextcloud = (fileId: number): void => {
  window.open(`${window.location.origin}/f/${fileId}`, '_blank');
};

// Toggle file favorite
export const toggleFileFavorite = async (
  userId: string,
  path: string,
  isFavorite: boolean
): Promise<void> => {
  try {
    await apiClient.request({
      method: 'PROPPATCH',
      url: `/remote.php/dav/files/${encodeURIComponent(userId)}${path}`,
      headers: {
        'Content-Type': 'application/xml',
      },
      data: `<?xml version="1.0"?>
        <d:propertyupdate xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
          <d:set>
            <d:prop>
              <oc:favorite>${isFavorite ? 0 : 1}</oc:favorite>
            </d:prop>
          </d:set>
        </d:propertyupdate>`,
    });
  } catch (error) {
    console.error('Failed to toggle favorite:', error);
    throw error;
  }
};

// Search files
export const searchFiles = async (
  userId: string,
  query: string,
  limit: number = 20
): Promise<FileInfo[]> => {
  try {
    const response = await apiClient.request({
      method: 'SEARCH',
      url: `/remote.php/dav/`,
      headers: {
        'Content-Type': 'application/xml',
      },
      data: `<?xml version="1.0"?>
        <d:searchrequest xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">
          <d:basicsearch>
            <d:select>
              <d:prop>
                <d:getlastmodified />
                <d:getetag />
                <d:getcontenttype />
                <d:getcontentlength />
                <d:resourcetype />
                <oc:fileid />
                <oc:permissions />
                <oc:favorite />
              </d:prop>
            </d:select>
            <d:from>
              <d:scope>
                <d:href>/files/${encodeURIComponent(userId)}</d:href>
                <d:depth>infinity</d:depth>
              </d:scope>
            </d:from>
            <d:where>
              <d:like>
                <d:prop><d:displayname /></d:prop>
                <d:literal>%${query}%</d:literal>
              </d:like>
            </d:where>
            <d:limit><d:nresults>${limit}</d:nresults></d:limit>
          </d:basicsearch>
        </d:searchrequest>`,
    });

    const parser = new DOMParser();
    const doc = parser.parseFromString(response.data, 'application/xml');
    const responses = doc.getElementsByTagNameNS('DAV:', 'response');

    const files: FileInfo[] = [];

    for (const resp of Array.from(responses)) {
      const href = resp.getElementsByTagNameNS('DAV:', 'href')[0]?.textContent || '';
      const resourceType = resp.getElementsByTagNameNS('DAV:', 'resourcetype')[0];
      const isDirectory = !!resourceType?.getElementsByTagNameNS('DAV:', 'collection')[0];

      const basePath = `/remote.php/dav/files/${encodeURIComponent(userId)}`;
      let path = decodeURIComponent(href);
      if (path.startsWith(basePath)) {
        path = path.substring(basePath.length);
      }

      files.push({
        id: parseInt(
          resp.getElementsByTagNameNS('http://owncloud.org/ns', 'fileid')[0]?.textContent || '0',
          10
        ),
        name: path.split('/').pop() || path,
        path,
        type: isDirectory ? 'directory' : 'file',
        mimeType:
          resp.getElementsByTagNameNS('DAV:', 'getcontenttype')[0]?.textContent ||
          (isDirectory ? 'inode/directory' : 'application/octet-stream'),
        size: parseInt(
          resp.getElementsByTagNameNS('DAV:', 'getcontentlength')[0]?.textContent || '0',
          10
        ),
        modified: new Date(
          resp.getElementsByTagNameNS('DAV:', 'getlastmodified')[0]?.textContent || ''
        ).toISOString(),
        etag: resp.getElementsByTagNameNS('DAV:', 'getetag')[0]?.textContent || '',
        permissions:
          resp.getElementsByTagNameNS('http://owncloud.org/ns', 'permissions')[0]?.textContent ||
          '',
        favorite:
          resp.getElementsByTagNameNS('http://owncloud.org/ns', 'favorite')[0]?.textContent === '1',
        isShared: false,
        isFederated: false,
      });
    }

    return files;
  } catch (error) {
    console.error('Failed to search files:', error);
    throw error;
  }
};
