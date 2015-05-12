#!/usr/bin/env python3
"""

noticeboards.py -- Maintains a listing of noticeboard topics

(c) 2010 James Hare - https://en.wikipedia.org/wiki/User:Harej
(c) 2015 Kunal Mehta - https://www.mediawiki.org/wiki/User:Legoktm

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 675 Mass Ave, Cambridge, MA 02139, USA.

Developers (add your self here if you worked on the code):
  James Hare - [[User:Harej]] - Wrote initial version
  Kunal Mehta - [[User:Legoktm]] - Ported to Python

"""


from collections import OrderedDict
import pywikibot
from pywikibot.data import api
from urllib.parse import quote


site = pywikibot.Site('en', 'wikipedia')


def parse_sections(page_title):
    req = api.Request(site=site, action='parse', page=page_title, prop='sections')
    return req.submit()


def process_boards(boards, save_page):
    noticeboard_listing = ''

    for topic, page_title in boards.items():
        found_sections = []
        sections = parse_sections(page_title)['parse']['sections']
        for section in sections:
            if section['level'] == '2':
                found_sections.append(section['line'])

        if topic != 'The Teahouse':  # Teahouse posts are in reverse order.
            found_sections.reverse()  # I put my thang down, flip it, and reverse it.

        header_listing = ''
        listing = '<div style="padding-left:3em;">'
        for count, section in enumerate(found_sections):
            link = quote(section.replace(' ', '_'), safe='')
            for replacement in ['{{', '}}', '[[', ']]', '~~~~']:
                section = section.replace(replacement, '<nowiki>%s</nowiki>' % replacement)
            if count < 3:
                header_listing += '* [[{page}#{link}|{section}]]\n'.format(page=page_title, link=link, section=section)
            else:
                listing += '* [[{page_title}#{link}|{section}]]\n'.format(
                    page_title=page_title,
                    link=link,
                    section=section
                )
        listing += '</div>'
        header_listing += '</div>'  # Wrapping up div started below.
        noticeboard_listing += '{{' + "Dashboard grouping|1='''[[{page}|{topic}]]''' ({count} threads)<br />" \
                                      "<div style='font-size:85%; padding-left:1.5em;'>" \
                                      "''Most recent:''\n{header_listing}".format(
                                          page=page_title,
                                          topic=topic,
                                          count=len(found_sections),
                                          header_listing=header_listing,
                                      ) + '}}\n%s\n\n{{collapse bottom}}\n' % listing

    pg = pywikibot.Page(site, save_page)
    # pywikibot.showDiff(pg.get(), noticeboard_listing)
    pg.text = noticeboard_listing
    pywikibot.output('Updating [[%s]]' % pg.title())
    pg.save('Updating Noticeboard topics', minor=False, botflag=True)

if __name__ == '__main__':
    process_boards(OrderedDict([
        ("Administrators' noticeboard", "Wikipedia:Administrators' noticeboard"),
        ("Administrators' noticeboard: Incidents", "Wikipedia:Administrators' noticeboard/Incidents"),
        ("Edit warring noticeboard", "Wikipedia:Administrators' noticeboard/Edit warring"),
        ("Bureaucrats' noticeboard", "Wikipedia:Bureaucrats' noticeboard"),
        ("Bot owners' noticeboard", "Wikipedia:Bot owners' noticeboard"),
        ("Arbitration Committee noticeboard", "Wikipedia:Arbitration Committee/Noticeboard"),
        ("Arbitration Enforcement noticeboard", "Wikipedia:Arbitration/Requests/Enforcement"),
        # ("Wikiquette alerts", "Wikipedia:Wikiquette alerts"),

    ]), 'Wikipedia:Dashboard/Administrative noticeboards')
    process_boards(OrderedDict([
        # ("Content noticeboard", "Wikipedia:Content noticeboard"),
        ("BLP noticeboard", "Wikipedia:Biographies of living persons/Noticeboard"),
        # ("Ethnic and religious conflict noticeboard",
        #  "Wikipedia:Administrators' noticeboard/Geopolitical ethnic and religious conflicts"),
        # ("Fiction noticeboard", "Wikipedia:Fiction/Noticeboard"),
        ("Fringe theories noticeboard", "Wikipedia:Fringe theories/Noticeboard"),
        ("Original research noticeboard", "Wikipedia:No original research/Noticeboard"),
        ("Reliable sources noticeboard", "Wikipedia:Reliable sources/Noticeboard"),
        # ("Notability noticeboard", "Wikipedia:Notability/Noticeboard"),
        ("Neutral point of view noticeboard", "Wikipedia:Neutral point of view/Noticeboard"),
        ("External Links noticeboard", "Wikipedia:External links/Noticeboard"),
        ("Conflict of interest noticeboard", "Wikipedia:Conflict of interest/Noticeboard"),
        ("Non-free content review", "Wikipedia:Non-free content review"),
        ("Dispute resolution noticeboard", "Wikipedia:Dispute resolution noticeboard"),
    ]), 'Wikipedia:Dashboard/Editorial noticeboards')

    process_boards(OrderedDict([
        # ("New user help", "Wikipedia:New contributors' help page/questions"),
        ("Editor assistance", "Wikipedia:Editor assistance/Requests"),
        ("Help desk", "Wikipedia:Help desk"),
        # ("Requests for feedback", "Wikipedia:Requests for feedback"),
        # ("Drawing board", "Wikipedia:Drawing board"),
        ("Media copyright questions", "Wikipedia:Media copyright questions"),
        ("The Teahouse", "Wikipedia:Teahouse/Questions"),

    ]), 'Wikipedia:Dashboard/Help noticeboards')

    process_boards(OrderedDict([
        ('Village Pump (policy)', 'Wikipedia:Village pump (policy)'),
        ('Village Pump (technical)', 'Wikipedia:Village pump (technical)'),
        ('Village Pump (proposals)', 'Wikipedia:Village pump (proposals)'),
        ('Village Pump (idea lab)', 'Wikipedia:Village pump (idea lab)'),
        ('Village Pump (miscellaneous)', 'Wikipedia:Village pump (miscellaneous)')
    ]), 'Wikipedia:Dashboard/Village pump')
